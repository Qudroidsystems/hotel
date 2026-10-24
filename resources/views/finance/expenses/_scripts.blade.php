<script>
document.addEventListener('DOMContentLoaded', function () {
    // Payment method → show the bank account box or the till option.
    document.querySelectorAll('#p_method').forEach(sel => {
        const form = sel.closest('form'), sync = () => {
            form.querySelectorAll('.p-bank').forEach(e => e.style.display = ['transfer', 'cheque'].includes(sel.value) ? '' : 'none');
            form.querySelectorAll('.p-cash').forEach(e => e.style.display = sel.value === 'cash' ? '' : 'none');
        };
        sel.addEventListener('change', sync); sync();
    });
    // Supplier chosen → payee name not needed.
    document.querySelectorAll('#x_supplier').forEach(sel => {
        const payee = sel.closest('form').querySelector('#x_payee'), sync = () => { payee.disabled = !!sel.value; if (sel.value) payee.value = ''; };
        sel.addEventListener('change', sync); sync();
    });
});
</script>
