@once
@push('scripts')
<script>
document.querySelectorAll('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.closeDialog)?.close()));
@if ($errors->any() && old('dialog_id'))
const failedDialog = document.getElementById(@json(old('dialog_id')));
const failedAlert = document.querySelector('.admin-finance-page-error');
if (failedDialog && failedAlert) {
    failedAlert.classList.add('admin-finance-dialog-error');
    failedDialog.querySelector('form')?.prepend(failedAlert);
    failedDialog.showModal();
}
@endif
</script>
@endpush
@endonce
