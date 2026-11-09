<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['complete', 'reopen', 'add_goal', 'propose_root', 'expand', 'approve', 'approve_breakdown', 'reject', 'edit', 'resolve', 'document', 'prepare_batch', 'publish'])],
            'node_id' => ['required_if:action,complete,reopen,expand,approve,approve_breakdown,reject,edit,resolve', 'string', 'max:100'],
            'title' => ['required_if:action,edit,add_goal', 'string', 'max:180'],
            'description' => ['required_if:action,edit,add_goal', 'string', 'max:10000'],
            'criteria' => ['sometimes', 'array', 'max:30'],
            'criteria.*' => ['required', 'string', 'max:1000'],
            'dependencies' => ['sometimes', 'array', 'max:30'],
            'dependencies.*' => ['string', 'distinct', 'max:100'],
            'answer' => ['required_if:action,resolve', 'string', 'max:3000'],
            'document_id' => ['nullable', 'string', 'max:100'],
            'name' => ['required_if:action,document', 'string', 'max:180'],
            'content' => ['required_if:action,document', 'string', 'max:100000'],
            'node_ids' => ['required_if:action,prepare_batch', 'array', 'min:1', 'max:100'],
            'node_ids.*' => ['string', 'distinct', 'max:100'],
            'batch_id' => ['required_if:action,publish', 'string', 'max:100'],
        ];
    }
}
