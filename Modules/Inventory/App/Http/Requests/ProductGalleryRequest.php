<?php

namespace Modules\Inventory\App\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class ProductGalleryRequest extends FormRequest
{

    public function rules(): array
    {
        // Rules for 'GET' and 'DELETE' methods return an empty array, as they typically don't require validation
        if (in_array($this->method(), ['GET', 'DELETE'])) {
            return [];
        }

        // Common validation rules for 'POST', 'PUT', and 'PATCH' methods
        return [
            'product_id' => 'required',
            'type' => 'required|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp'
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success'   => false,
            'message'   => 'Form Validation errors',
            'data'      => $validator->errors()
        ]));
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
