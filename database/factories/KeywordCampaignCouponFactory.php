<?php

namespace Database\Factories;

use App\Enums\KeywordCouponStatus;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<KeywordCampaignCoupon> */
class KeywordCampaignCouponFactory extends Factory
{
    protected $model = KeywordCampaignCoupon::class;

    public function definition(): array
    {
        return [
            'keyword_campaign_id' => KeywordCampaign::factory(),
            'code' => 'CURSO-'.Str::upper(Str::random(8)),
            'status' => KeywordCouponStatus::Disponivel,
            'reference' => 'cupom-'.Str::lower(Str::random(10)),
        ];
    }
}
