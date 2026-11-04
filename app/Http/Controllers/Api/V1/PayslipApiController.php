<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payroll\Payslip;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\Request;

/** The staff app's "My payslips": a person's own payslips from approved and paid payrolls. */
class PayslipApiController extends ApiController
{
    public function __construct(private PayrollService $payroll)
    {
    }

    /** GET /me/payslips */
    public function index(Request $request)
    {
        return $this->ok($this->payroll->mine($request->user())->map(fn ($s) => $this->payroll->slipJson($s))->values());
    }

    /** GET /me/payslips/{id} */
    public function show(Request $request, int $payslip)
    {
        $s = $this->payroll->mine($request->user())->firstWhere('id', $payslip);
        abort_unless($s instanceof Payslip, 404);

        return $this->ok($this->payroll->slipJson($s));
    }
}
