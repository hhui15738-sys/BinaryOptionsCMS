<?php

namespace App\Http\Requests\MainHttp\Profile;

use App\Rules\IdIfAdminRule;
use Illuminate\Foundation\Http\FormRequest;

class PassportFirstPageRequest extends FormRequest
{

    public function rules()
    {
        return [
            'id' => [new IdIfAdminRule],
            'page' => ['required', 'integer', 'in:1,2,3'],
            'file' => [
                'required',
                'file',
                'max:4096',
                'mimetypes:image/jpeg,image/png,image/webp',
                function ($attribute, $value, $fail) {
                    if (!$value->isValid() || @getimagesize($value->getRealPath()) === false) {
                        $fail('The uploaded document must be a valid image.');
                    }
                },
            ],
        ];
    }
}
