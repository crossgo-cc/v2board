<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\PlanSave;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PlanSaveValidationTest extends TestCase
{
    public function testResetPriceRejectsNegativeValues()
    {
        $rules = (new PlanSave())->rules();
        $validator = Validator::make(
            ['reset_price' => -1],
            ['reset_price' => $rules['reset_price']]
        );

        $this->assertTrue($validator->fails());
    }

    public function testResetPriceAcceptsNullZeroAndPositiveValues()
    {
        $rules = (new PlanSave())->rules();

        foreach ([null, 0, 100] as $resetPrice) {
            $validator = Validator::make(
                ['reset_price' => $resetPrice],
                ['reset_price' => $rules['reset_price']]
            );

            $this->assertFalse($validator->fails());
        }
    }
}
