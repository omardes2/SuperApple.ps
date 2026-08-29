<?php

namespace Tests\Feature\Sprint7;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_local_number_gets_default_country_code(): void
    {
        $this->assertSame('+970591234567', PhoneNormalizer::normalize('0591234567', '970'));
    }

    public function test_international_plus_is_preserved(): void
    {
        $this->assertSame('+972591234567', PhoneNormalizer::normalize('+972 59 123 4567', '970'));
    }

    public function test_double_zero_prefix_is_treated_as_international(): void
    {
        $this->assertSame('+970591234567', PhoneNormalizer::normalize('00970591234567', '970'));
    }

    public function test_bare_local_without_trunk_zero_gets_code(): void
    {
        $this->assertSame('+970591234567', PhoneNormalizer::normalize('591234567', '970'));
    }

    public function test_invalid_numbers_return_null(): void
    {
        $this->assertNull(PhoneNormalizer::normalize('', '970'));
        $this->assertNull(PhoneNormalizer::normalize('123', '970'));
        $this->assertNull(PhoneNormalizer::normalize('abc', '970'));
    }

    public function test_national_number_without_country_code_setting_is_rejected(): void
    {
        $this->assertNull(PhoneNormalizer::normalize('0591234567', null));
    }
}
