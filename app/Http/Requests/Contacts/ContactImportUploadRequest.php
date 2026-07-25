<?php

namespace App\Http\Requests\Contacts;

use App\Services\SystemSettingService;
use Illuminate\Foundation\Http\FormRequest;

class ContactImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.import') ?? false;
    }

    public function rules(): array
    {
        $maxMb = (int) app(SystemSettingService::class)->get('contacts.import_max_file_size', 10);

        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:'.($maxMb * 1024)],
        ];
    }
}
