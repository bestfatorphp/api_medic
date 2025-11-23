<?php

namespace App\Services;

use App\Models\UserMT;
use Illuminate\Support\Arr;
use JetBrains\PhpStorm\ArrayShape;

class UserMtDifferencesService
{
    /**
     * Ищём пользователя и различия его данных с данными запроса
     * @param array $data
     * @return array
     */
    public function searchUsers(array $data): array
    {
        $phone = Arr::get($data, 'phone');
        $email = Arr::get($data, 'email');
        $searchFields = [
            'full_name' => Arr::get($data, 'name'),
            'email' => $email ? strtolower($email) : $email,
            'phone' => $phone ? $this->normalizePhone($phone) : $phone,
            'medtouch_uuid' => Arr::get($data, 'medtouch_uuid'),
            'oralink_uuid' => Arr::get($data, 'oralink_uuid'),
        ];

        $user = null;
        if (!is_null($searchFields['email']) || !is_null($searchFields['medtouch_uuid']) || !is_null($searchFields['oralink_uuid'])) {
            //ищем пользователя по уникальным полям (email, medtouch_uuid, oralink_uuid)
            $user = $this->findUserByUniqueFields($searchFields);
        }

        if (!$user) {
            return [
                'match_type' => 'none',
                'message' => 'Пользователь не найден',
                'request' => $searchFields
            ];
        }

        //анализируем совпадения
        $matchAnalysis = $this->analyzeMatch($user, $searchFields);

        //полное совпадение
        if (empty($matchAnalysis['non_matched'])) {
            return [
                'match_type' => 'exact',
                'message' => 'Полное совпадение по всем полям',
                'request' => $searchFields,
                'matched_fields' => $matchAnalysis['matched'],
                'non_matched_fields' => $matchAnalysis['non_matched']
            ];
        }

        //частичное совпадение
        return [
            'match_type' => 'partial',
            'message' => 'Найдены расхождения в полях',
            'request' => $searchFields,
            'user_data' => [
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'medtouch_uuid' => $user->medtouch_uuid,
                'oralink_uuid' => $user->oralink_uuid,
            ],
            'matched_fields' => $matchAnalysis['matched'],
            'non_matched_fields' => $matchAnalysis['non_matched']
        ];
    }

    /**
     * Ищем пользователя
     * @param array $searchFields
     * @return UserMT|null
     */
    private function findUserByUniqueFields(array $searchFields): ?UserMT
    {
        $conditions = [];

        if (!empty($searchFields['email'])) {
            $conditions[] = ['email', '=', $searchFields['email']];
        }

        if (!empty($searchFields['medtouch_uuid'])) {
            $conditions[] = ['medtouch_uuid', '=', $searchFields['medtouch_uuid']];
        }

        if (!empty($searchFields['oralink_uuid'])) {
            $conditions[] = ['oralink_uuid', '=', $searchFields['oralink_uuid']];
        }

        if (empty($conditions)) {
            return null;
        }

        return UserMT::where(function ($q) use ($conditions) {
            foreach ($conditions as $condition) {
                $q->orWhere($condition[0], $condition[1], $condition[2]);
            }
        })->first();
    }

    /**
     * Анализируем совпадения
     * @param UserMT $user
     * @param array $searchFields
     * @return array[]
     */
    #[ArrayShape(['matched' => "array", 'non_matched' => "array"])]
    private function analyzeMatch(UserMT $user, array $searchFields): array
    {
        $matched = [];
        $nonMatched = [];

        foreach ($searchFields as $field => $searchValue) {
            $userValue = $user->{$field};

            if ($userValue == $searchValue) {
                $matched[] = $field;
            } else {
                $nonMatched[] = $field;
            }
        }

        return [
            'matched' => $matched,
            'non_matched' => $nonMatched
        ];
    }

    /**
     * Нормализуем телефон
     * @param string $phone
     * @return string
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }
}
