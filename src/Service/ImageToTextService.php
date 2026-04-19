<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageToTextService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        // Try multiple possible environment variable names
        $this->apiKey = trim($_ENV['API_NINJAS_KEY'] ?? $_ENV['PROFANITY_FILTER_API_KEY'] ?? '');
    }

    /**
     * Extract text from image file using API Ninjas Image to Text endpoint
     * @param string $imagePath Path to the image file
     * @return array ['success' => bool, 'text' => string, 'error' => string|null]
     */
    public function extractTextFromImage(string $imagePath): array
    {
        $this->logger->info('=== IMAGE TO TEXT EXTRACTION START ===');

        try {
            // Validate image exists
            if (!file_exists($imagePath)) {
                $this->logger->error('Image file not found: ' . $imagePath);
                return [
                    'success' => false,
                    'text' => '',
                    'error' => 'Image file not found'
                ];
            }

            // Validate API key
            if (empty($this->apiKey)) {
                $this->logger->error('API Key not configured');
                return [
                    'success' => false,
                    'text' => '',
                    'error' => 'API Key not configured. Please set API_NINJAS_KEY in .env'
                ];
            }

            $this->logger->info('Image path: ' . $imagePath);
            $this->logger->info('API Key present: YES (length: ' . strlen($this->apiKey) . ')');
            $this->logger->info('File size: ' . (filesize($imagePath) / 1024) . ' KB');

            // Read image file
            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                $this->logger->error('Failed to read image file');
                return [
                    'success' => false,
                    'text' => '',
                    'error' => 'Failed to read image file'
                ];
            }

            // Get MIME type
            $mimeType = mime_content_type($imagePath);
            if ($mimeType === false) {
                $mimeType = 'image/jpeg'; // Fallback
            }
            $this->logger->info('Image MIME type: ' . $mimeType);

            // Call API Ninjas Image to Text endpoint
            $url = 'https://api.api-ninjas.com/v1/imagetotext';
            $this->logger->info('Calling API at: ' . $url);

            try {
                // Send as multipart form data with binary image data
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'X-Api-Key' => $this->apiKey,
                    ],
                    'body' => [
                        'image' => fopen($imagePath, 'r'),
                    ],
                    'timeout' => 30
                ]);

                $statusCode = $response->getStatusCode();
                $this->logger->info('API Response status: ' . $statusCode);

                if ($statusCode !== 200) {
                    $this->logger->error('API returned error status: ' . $statusCode);
                    $responseContent = $response->getContent(false);
                    $this->logger->error('Response body: ' . $responseContent);
                    
                    // Try to parse error details
                    $errorDetail = 'API error: ' . $statusCode;
                    try {
                        $errorData = json_decode($responseContent, true);
                        if (isset($errorData['error'])) {
                            $errorDetail = $errorData['error'];
                        }
                    } catch (\Exception $e) {
                        // Keep default error message
                    }
                    
                    return [
                        'success' => false,
                        'text' => '',
                        'error' => $errorDetail
                    ];
                }

                $data = $response->toArray();
                $this->logger->info('API Response parsed successfully');
                $this->logger->info('Response data: ' . json_encode($data));
                $this->logger->info('Response fields: ' . implode(', ', array_keys((array)$data)));

            } catch (\Exception $e) {
                $this->logger->error('Error sending request to API: ' . $e->getMessage());
                $this->logger->error('Stack trace: ' . $e->getTraceAsString());
                return [
                    'success' => false,
                    'text' => '',
                    'error' => 'Failed to connect to API Ninjas: ' . $e->getMessage()
                ];
            }

            // Extract text from response
            // API Ninjas returns either:
            // 1. Array of text objects: [{"text": "...", "confidence": 0.95, ...}, ...]
            // 2. Direct text field: {"text": "..."}
            $extractedText = '';
            
            if (is_array($data)) {
                // Check if it's an array of text objects (API v2 format)
                if (isset($data[0]) && is_array($data[0]) && isset($data[0]['text'])) {
                    $this->logger->info('API returned array of text objects');
                    // Concatenate all text fields
                    $textParts = [];
                    foreach ($data as $item) {
                        if (isset($item['text']) && !empty($item['text'])) {
                            $textParts[] = trim($item['text']);
                        }
                    }
                    $extractedText = implode(' ', $textParts);
                } 
                // Check if it has a 'text' key directly
                elseif (isset($data['text'])) {
                    $this->logger->info('API returned text field directly');
                    $extractedText = $data['text'];
                }
            }
            
            $this->logger->info('Extracted text length: ' . strlen($extractedText) . ' characters');
            $this->logger->info('Extracted text preview: ' . substr($extractedText, 0, 200));

            if (empty($extractedText)) {
                $this->logger->warning('No text extracted from image');
                $this->logger->info('Full response data: ' . json_encode($data));
                return [
                    'success' => false,
                    'text' => '',
                    'error' => 'No text found in image. The image might not contain readable text or the text might be too small/unclear.'
                ];
            }

            $this->logger->info('Text extraction successful');
            $this->logger->info('=== IMAGE TO TEXT EXTRACTION END ===');

            return [
                'success' => true,
                'text' => $extractedText,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error('EXCEPTION in extractTextFromImage:');
            $this->logger->error('  Message: ' . $e->getMessage());
            $this->logger->error('  Code: ' . $e->getCode());
            $this->logger->error('  File: ' . $e->getFile() . ':' . $e->getLine());
            $this->logger->error('  Stack: ' . $e->getTraceAsString());
            $this->logger->error('=== IMAGE TO TEXT EXTRACTION END (Exception) ===');

            return [
                'success' => false,
                'text' => '',
                'error' => 'Error extracting text: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract text from multiple image files
     * @param array $imagePaths Array of image file paths
     * @return array Combined extracted text from all images
     */
    public function extractTextFromMultipleImages(array $imagePaths): array
    {
        $this->logger->info('=== BATCH IMAGE TO TEXT EXTRACTION START ===');
        $this->logger->info('Processing ' . count($imagePaths) . ' images');

        $allText = [];
        $failedImages = [];

        foreach ($imagePaths as $index => $imagePath) {
            $this->logger->info('Processing image ' . ($index + 1) . ' of ' . count($imagePaths));

            $result = $this->extractTextFromImage($imagePath);

            if ($result['success']) {
                $allText[] = $result['text'];
                $this->logger->info('Image ' . ($index + 1) . ' processed successfully');
            } else {
                $failedImages[] = [
                    'path' => $imagePath,
                    'error' => $result['error']
                ];
                $this->logger->warning('Image ' . ($index + 1) . ' failed: ' . $result['error']);
            }
        }

        $this->logger->info('Batch processing complete - Success: ' . count($allText) . ', Failed: ' . count($failedImages));
        $this->logger->info('=== BATCH IMAGE TO TEXT EXTRACTION END ===');

        return [
            'success' => count($allText) > 0,
            'texts' => $allText,
            'combined_text' => implode("\n---\n", $allText),
            'failed' => $failedImages
        ];
    }
}

