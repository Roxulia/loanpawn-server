@once
@push('scripts')
<script>
document.querySelectorAll('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.closeDialog)?.close()));
@if ($errors->any() && old('dialog_id')) document.getElementById(@json(old('dialog_id')))?.showModal(); @endif
</script>
@endpush
@endonce
