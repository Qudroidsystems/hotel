{{-- Bank details for paying the company's account. $bankAccounts, $ref --}}
@if ($bankAccounts->isNotEmpty())
    <div class="note">
        <strong>Pay by bank transfer</strong> — please use <strong>{{ $ref }}</strong> as the payment reference.
        <table style="margin-top:8px;font-size:12.5px">
            <tbody>
                @foreach ($bankAccounts as $acc)
                    <tr><td style="padding:4px 0"><strong>{{ $acc->bank_name }}</strong>{{ $acc->branch ? ', ' . $acc->branch : '' }}</td>
                        <td style="padding:4px 8px"><span class="ref">{{ $acc->account_number }}</span></td>
                        <td style="padding:4px 0">{{ $acc->account_name }}{{ $acc->currency !== 'NGN' ? ' (' . $acc->currency . ')' : '' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
