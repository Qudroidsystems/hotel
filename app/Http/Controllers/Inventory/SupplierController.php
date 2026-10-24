<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View stock')->only(['index']);
        $this->middleware('permission:Manage suppliers')->except(['index']);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));
        $suppliers = Supplier::withCount(['purchaseOrders', 'receipts'])->withSum('receipts as bought', 'total')
            ->withMax('receipts as last_delivery', 'received_on')
            ->withSum(['invoices as invoiced' => fn ($i) => $i->open()], 'amount')->withSum(['invoices as invoice_paid' => fn ($i) => $i->open()], 'paid')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('contact_person', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->orderByDesc('is_active')->orderBy('name')->get();
        $pagetitle = 'Suppliers';

        return view('inventory.suppliers', compact('pagetitle', 'suppliers', 'q'));
    }

    public function store(Request $request)
    {
        Supplier::create(array_filter($this->validated($request), fn ($v) => $v !== null) + ['is_active' => true]);

        return back()->with('success', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);
        $supplier->payment_terms_days ??= 30;
        $supplier->save();

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists() || $supplier->receipts()->exists()) {
            $supplier->update(['is_active' => false]);

            return back()->with('success', 'This supplier has orders or deliveries on record, so it was switched off instead of deleted.');
        }
        $supplier->delete();

        return back()->with('success', 'Supplier deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:150',
            'address'        => 'nullable|string|max:255',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes'          => 'nullable|string|max:255',
        ]);
    }
}
