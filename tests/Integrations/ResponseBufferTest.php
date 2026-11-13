<?php

namespace TelescopeMonitor\Tests\Integrations;

use PHPUnit\Framework\TestCase;
use TelescopeMonitor\Integrations\ResponseBuffer;

final class ResponseBufferTest extends TestCase
{
    public function test_repository_pages_above_the_normal_transport_limit_are_received_in_full(): void
    {
        $json = json_encode(array_fill(0, 30, ['name' => 'app', 'description' => str_repeat('Repository description ', 200)]), JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(65536, strlen($json));
        $normal = new ResponseBuffer(65536);
        $discovery = new ResponseBuffer(1048576);
        foreach (str_split($json, 16384) as $chunk) {
            $normal->append($chunk);
            $this->assertSame(strlen($chunk), $discovery->append($chunk));
        }
        $this->assertTrue($normal->exceeded);
        $this->assertSame('', $normal->body);
        $this->assertFalse($discovery->exceeded);
        $this->assertCount(30, json_decode($discovery->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_oversized_response_is_discarded_and_cannot_resume(): void
    {
        $buffer = new ResponseBuffer(1024);
        $this->assertSame(1024, $buffer->append(str_repeat('a', 1024)));
        $this->assertSame(0, $buffer->append('b'));
        $this->assertSame('', $buffer->body);
        $this->assertSame(0, $buffer->append('small'));
    }
}
