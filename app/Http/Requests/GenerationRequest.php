<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'in:low,medium,high'],
            'reference_images' => ['present', 'array', 'max:4', 'list'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            'quote' => [$this->routeIs('generations.store') ? 'required' : 'sometimes', 'string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference must be a valid http or https image URL.',
            'reference_images.max' => 'Use at most four reference image URLs.',
            'prompt.max' => 'Keep the prompt to 4,000 characters or fewer.',
            'quote.uuid' => 'Please get a new estimate before generating.',
        ];
    }

    /** @return array{prompt: string, size: string, quality: string, reference_images: list<string>} */
    public function parameters(): array
    {
        return [
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => array_values(array_map(fn (string $url): string => $url, $this->validated('reference_images'))),
        ];
    }
}
