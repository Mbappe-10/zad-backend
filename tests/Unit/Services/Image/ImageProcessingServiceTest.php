<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\ImageProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\EncodedImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    private const MAX_OUTPUT_BYTES = 500 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_portrait_image_keeps_its_full_aspect_ratio(): void
    {
        $this->assertProcessedImageDimensions(
            sourceWidth: 1000,
            sourceHeight: 2000,
            expectedWidth: 600,
            expectedHeight: 1200,
        );
    }

    public function test_landscape_image_keeps_its_full_aspect_ratio(): void
    {
        $this->assertProcessedImageDimensions(
            sourceWidth: 2000,
            sourceHeight: 1000,
            expectedWidth: 1200,
            expectedHeight: 600,
        );
    }

    public function test_square_image_is_scaled_down_to_the_output_bounds(): void
    {
        $this->assertProcessedImageDimensions(
            sourceWidth: 1600,
            sourceHeight: 1600,
            expectedWidth: 1200,
            expectedHeight: 1200,
        );
    }

    public function test_image_within_the_output_bounds_is_not_enlarged(): void
    {
        $this->assertProcessedImageDimensions(
            sourceWidth: 800,
            sourceHeight: 600,
            expectedWidth: 800,
            expectedHeight: 600,
        );
    }

    public function test_output_that_cannot_fit_under_the_size_limit_fails_without_a_partial_file(): void
    {
        $service = app(ImageProcessingService::class);
        $manager = Mockery::mock(ImageManager::class);
        $image = Mockery::mock(ImageInterface::class);
        $oversizedWebp = new EncodedImage(
            str_repeat('x', self::MAX_OUTPUT_BYTES + 1),
            'image/webp',
        );

        $manager
            ->shouldReceive('decodePath')
            ->once()
            ->andReturn($image);

        $image->shouldNotReceive('cover');
        $image
            ->shouldReceive('scaleDown')
            ->once()
            ->with(1200, 1200)
            ->andReturnSelf();
        $image
            ->shouldReceive('contrast')
            ->once()
            ->with(3)
            ->andReturnSelf();
        $image
            ->shouldReceive('sharpen')
            ->once()
            ->with(5)
            ->andReturnSelf();
        $image
            ->shouldReceive('width')
            ->once()
            ->andReturn(1200);
        $image
            ->shouldReceive('height')
            ->once()
            ->andReturn(1200);
        $image
            ->shouldReceive('encodeUsingFormat')
            ->times(9)
            ->andReturn($oversizedWebp);

        $managerProperty = new ReflectionProperty(
            ImageProcessingService::class,
            'manager',
        );
        $managerProperty->setValue($service, $manager);

        try {
            $service->processProductImage(
                UploadedFile::fake()->image('noise.png', 1200, 1200),
                'tests/products',
            );

            self::fail('Expected an oversized WebP image to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                '500',
                $exception->getMessage(),
            );
        }

        self::assertSame([], Storage::disk('public')->allFiles());
    }

    private function assertProcessedImageDimensions(
        int $sourceWidth,
        int $sourceHeight,
        int $expectedWidth,
        int $expectedHeight,
    ): void {
        $result = app(ImageProcessingService::class)->processProductImage(
            UploadedFile::fake()->image(
                'product.png',
                $sourceWidth,
                $sourceHeight,
            ),
            'tests/products',
        );

        Storage::disk('public')->assertExists($result['path']);

        $savedPath = Storage::disk('public')->path($result['path']);
        $imageInformation = getimagesize($savedPath);
        $actualSize = filesize($savedPath);

        self::assertIsArray($imageInformation);
        self::assertIsInt($actualSize);
        self::assertSame($expectedWidth, $imageInformation[0]);
        self::assertSame($expectedHeight, $imageInformation[1]);
        self::assertSame($imageInformation[0], $result['width']);
        self::assertSame($imageInformation[1], $result['height']);
        self::assertSame('image/webp', $imageInformation['mime']);
        self::assertSame('image/webp', $result['mime_type']);
        self::assertStringEndsWith('.webp', $result['path']);
        self::assertSame($actualSize, $result['size_bytes']);
        self::assertLessThanOrEqual(
            self::MAX_OUTPUT_BYTES,
            $result['size_bytes'],
        );
    }
}
