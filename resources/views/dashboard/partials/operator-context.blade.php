@php
    /** @var \App\Models\User|null $currentOperator */
    $currentOperator = auth()->user();
    $companyName = optional(optional($currentOperator)->company)->name;
    $companyId = optional($currentOperator)->company_id;
    $operatorName = optional($currentOperator)->name;
    $operatorRole = optional($currentOperator)->operator_role;
@endphp
<div style="margin:0 0 12px 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;font-size:13px;color:#374151;">
    <strong>Tenant:</strong> {{ $companyName ?: 'Unbound Tenant' }} (ID: {{ $companyId ?? 'N/A' }})
    &nbsp;|&nbsp;
    <strong>Operator:</strong> {{ $operatorName ?? 'N/A' }}
    &nbsp;|&nbsp;
    <strong>Role:</strong> {{ $operatorRole ?: 'N/A' }}
</div>
