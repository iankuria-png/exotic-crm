<?php

namespace App\Services\Kyc\Ai;

use Opis\JsonSchema\Validator;
use RuntimeException;

class KycObservationSchema
{
    public static function schema(): array
    {
        $nullable = fn ($type) => ['type' => [$type, 'null']];
        $properties = [
            'legal_name' => $nullable('string') + ['maxLength' => 200],
            'dob' => $nullable('string') + ['maxLength' => 10],
            'nationality' => $nullable('string') + ['maxLength' => 100],
            'document_number_last4' => $nullable('string') + ['maxLength' => 4],
            'readable' => ['type' => 'boolean'], 'document_type_matches' => ['type' => 'boolean'],
            'expired' => $nullable('boolean'), 'tamper_suspected' => $nullable('boolean'),
            'screen_or_copy' => $nullable('boolean'), 'single_face' => ['type' => 'boolean'],
            'poses_consistent' => ['type' => 'boolean'],
            'face_match' => ['type' => 'string', 'enum' => ['likely_same', 'likely_different', 'inconclusive', 'declined']],
            'face_match_confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'quality_problems' => ['type' => 'array', 'maxItems' => 6, 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'reason'],
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['id_front', 'id_back', 'selfie']],
                    'reason' => ['type' => 'string', 'enum' => ['blur', 'glare', 'cropped', 'wrong_side', 'too_dark', 'no_face']],
                ],
            ]],
        ];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    public static function validate(mixed $value): array
    {
        $object = json_decode(json_encode($value));
        if (! (new Validator)->validate($object, json_decode(json_encode(self::schema())))->isValid()) {
            throw new RuntimeException('invalid_observation_schema');
        }

        return json_decode(json_encode($object), true);
    }
}
