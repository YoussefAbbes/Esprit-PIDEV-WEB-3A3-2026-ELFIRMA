<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LivestockDnaDetectionController extends AbstractController
{
    #[Route('/livestock/dna-detect', name: 'livestock_dna_detect', methods: ['GET'])]
    public function detect(Request $request): JsonResponse
    {
        $type = strtolower(trim((string) $request->query->get('type', '')));
        $observations = [
            'body' => strtolower(trim((string) $request->query->get('body', ''))),
            'neck' => strtolower(trim((string) $request->query->get('neck', ''))),
            'secondary' => strtolower(trim((string) $request->query->get('secondary', ''))),
            'genital' => strtolower(trim((string) $request->query->get('genital', ''))),
            'behavior' => strtolower(trim((string) $request->query->get('behavior', ''))),
            'panel_data' => [],
        ];

        $panelDataRaw = trim((string) $request->query->get('panel_data', ''));
        if ($panelDataRaw !== '') {
            $decodedPanelData = json_decode($panelDataRaw, true);
            if (is_array($decodedPanelData)) {
                $observations['panel_data'] = array_map(
                    static fn ($value) => strtolower(trim((string) $value)),
                    $decodedPanelData
                );
            }
        }

        $profiles = [
            'poultry' => [
                'organism' => 'Gallus gallus',
                'system' => 'ZZ/ZW chromosome system',
                'male_label' => 'Rooster',
                'female_label' => 'Hen',
            ],
            'bovin' => [
                'organism' => 'Bos taurus',
                'system' => 'XY chromosome system',
                'male_label' => 'Bull',
                'female_label' => 'Cow',
            ],
            'sheep' => [
                'organism' => 'Ovis aries',
                'system' => 'XY chromosome system',
                'male_label' => 'Ram',
                'female_label' => 'Ewe',
            ],
            'pig' => [
                'organism' => 'Sus scrofa',
                'system' => 'XY chromosome system',
                'male_label' => 'Boar',
                'female_label' => 'Sow',
            ],
            'other' => [
                'organism' => 'Other livestock',
                'system' => 'Unknown',
                'male_label' => 'Male',
                'female_label' => 'Female',
            ],
        ];

        if (!isset($profiles[$type])) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid type. Allowed values: poultry, bovin, sheep, pig, other.',
            ], 400);
        }

        $profile = $profiles[$type];
        $analysis = $this->analyzeVisualClues($type, $observations);
        $seedMaterial = $this->buildSeedMaterial($type, $observations, $analysis);
        $sequence = $this->generateSyntheticDnaSequence($type, $observations, $analysis, $seedMaterial);

        return $this->json([
            'success' => true,
            'source' => 'local-synthetic',
            'type' => $type,
            'organism' => $profile['organism'],
            'system' => $profile['system'],
            'dna_sequence' => $sequence,
            'detected_gender' => $analysis['detected_gender'],
            'sex' => $analysis['sex'],
            'observations' => $observations,
            'matched_clues' => $analysis['matched_clues'],
            'conflicting_clues' => $analysis['conflicting_clues'],
            'sequence_length' => strlen($sequence),
            'seed' => hash('sha256', $seedMaterial),
            'explanation' => $analysis['explanation'],
        ]);
    }

    /**
     * @param array{body:string,neck:string,secondary:string,genital:string,behavior:string,panel_data:array<string,string>} $observations
     * @return array{sex:string,detected_gender:string,explanation:string,matched_clues:array<int,string>,conflicting_clues:array<int,string>}
     */
    private function analyzeVisualClues(string $type, array $observations): array
    {
        $maleScore = 0;
        $femaleScore = 0;
        $matchedClues = [];
        $conflictingClues = [];
        $panel = $observations['panel_data'] ?? [];

        if (in_array($observations['body'], ['broad', 'very broad'], true)) {
            $maleScore += 2;
            $matchedClues[] = 'Broad body outline';
        } elseif (in_array($observations['body'], ['fine', 'slender'], true)) {
            $femaleScore += 2;
            $matchedClues[] = 'Fine body outline';
        } elseif ($observations['body'] === 'balanced') {
            $matchedClues[] = 'Balanced body outline';
        }

        if (in_array($observations['neck'], ['thick', 'very thick'], true)) {
            $maleScore += 2;
            $matchedClues[] = 'Thick neck profile';
        }

        if ($observations['neck'] === 'fine') {
            $femaleScore += 2;
            $matchedClues[] = 'Fine neck profile';
        } elseif ($observations['neck'] === 'moderate') {
            $matchedClues[] = 'Moderate neck profile';
        }

        if (in_array($observations['secondary'], ['marked', 'prominent'], true)) {
            $maleScore += 2;
            $matchedClues[] = 'Pronounced secondary traits';
        } elseif (in_array($observations['secondary'], ['small', 'moderate'], true)) {
            $matchedClues[] = 'Limited secondary traits';
        } elseif ($observations['secondary'] === 'none') {
            $femaleScore += 1;
            $matchedClues[] = 'Few visible secondary traits';
        }

        if ($observations['genital'] === 'visible') {
            $matchedClues[] = 'Genital area clearly observed';
        } elseif ($observations['genital'] === 'partially visible') {
            $matchedClues[] = 'Genital area partially observed';
        } elseif ($observations['genital'] === 'uncertain') {
            $matchedClues[] = 'Genital area uncertain';
        } elseif ($observations['genital'] === 'not visible') {
            $matchedClues[] = 'Genital area not visible';
        }

        if (in_array($observations['behavior'], ['territorial', 'mounting', 'restless'], true)) {
            $maleScore += 2;
            $matchedClues[] = ucfirst($observations['behavior']) . ' behavior';
        } elseif ($observations['behavior'] === 'calm') {
            $femaleScore += 1;
            $matchedClues[] = 'Calm behavior';
        } elseif ($observations['behavior'] === 'alert') {
            $matchedClues[] = 'Alert posture';
        }

        if ($type === 'poultry') {
            if (in_array($panel['bio-poultry-comb-select'] ?? '', ['large', 'very large'], true)) {
                $maleScore += 4;
                $matchedClues[] = 'Large comb';
            } elseif (in_array($panel['bio-poultry-comb-select'] ?? '', ['flat', 'small'], true)) {
                $femaleScore += 3;
                $matchedClues[] = 'Small or flat comb';
            } elseif (($panel['bio-poultry-comb-select'] ?? '') === 'medium') {
                $matchedClues[] = 'Medium comb';
            }

            if (in_array($panel['bio-poultry-wattle-select'] ?? '', ['large'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Large wattle';
            } elseif (in_array($panel['bio-poultry-wattle-select'] ?? '', ['none', 'small'], true)) {
                $femaleScore += 2;
                $matchedClues[] = 'Small or absent wattle';
            } elseif (($panel['bio-poultry-wattle-select'] ?? '') === 'medium') {
                $matchedClues[] = 'Medium wattle';
            }

            if (in_array($panel['bio-poultry-tail-select'] ?? '', ['streaming', 'arched'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Streamed or arched tail feathers';
            } elseif (in_array($panel['bio-poultry-tail-select'] ?? '', ['rounded', 'short'], true)) {
                $femaleScore += 1;
                $matchedClues[] = 'Rounded or short tail feathers';
            } elseif (($panel['bio-poultry-tail-select'] ?? '') === 'medium') {
                $matchedClues[] = 'Moderate tail feathers';
            }
        } elseif ($type === 'bovin') {
            if (in_array($panel['bio-bovin-horns-select'] ?? '', ['developed horns', 'visible buds', 'asymmetrical'], true)) {
                $matchedClues[] = 'Horn development observed';
            } elseif (($panel['bio-bovin-horns-select'] ?? '') === 'none') {
                $matchedClues[] = 'No horn development observed';
            } elseif (($panel['bio-bovin-horns-select'] ?? '') === 'small buds') {
                $matchedClues[] = 'Small horn buds';
            }

            if (in_array($panel['bio-bovin-neck-select'] ?? '', ['thick', 'very thick'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Thick bovin neck';
            } elseif (($panel['bio-bovin-neck-select'] ?? '') === 'fine') {
                $femaleScore += 2;
                $matchedClues[] = 'Fine bovin neck';
            } elseif (($panel['bio-bovin-neck-select'] ?? '') === 'moderate') {
                $matchedClues[] = 'Moderate bovin neck';
            }

            if (in_array($panel['bio-bovin-pelvis-select'] ?? '', ['wide', 'very wide'], true)) {
                $femaleScore += 4;
                $matchedClues[] = 'Wide pelvis';
            } elseif (in_array($panel['bio-bovin-pelvis-select'] ?? '', ['narrow'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Narrow pelvis';
            } elseif (($panel['bio-bovin-pelvis-select'] ?? '') === 'medium') {
                $matchedClues[] = 'Medium pelvis width';
            }

            if (in_array($panel['bio-bovin-horns-select'] ?? '', ['developed horns', 'visible buds'], true) && in_array($panel['bio-bovin-pelvis-select'] ?? '', ['wide', 'very wide'], true)) {
                $conflictingClues[] = 'Horn development and wide pelvis suggest mixed signals';
            }
        } elseif ($type === 'sheep') {
            if (in_array($panel['bio-sheep-horn-select'] ?? '', ['curved', 'spiraled'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Curved or spiraled horns';
            } elseif (($panel['bio-sheep-horn-select'] ?? '') === 'none') {
                $matchedClues[] = 'No horn curl';
            } elseif (($panel['bio-sheep-horn-select'] ?? '') === 'small') {
                $matchedClues[] = 'Small horn curl';
            }

            if (in_array($panel['bio-sheep-neck-select'] ?? '', ['thick', 'broad'], true)) {
                $maleScore += 2;
                $matchedClues[] = 'Thicker sheep neck';
            } elseif (($panel['bio-sheep-neck-select'] ?? '') === 'fine') {
                $femaleScore += 2;
                $matchedClues[] = 'Fine sheep neck';
            } elseif (($panel['bio-sheep-neck-select'] ?? '') === 'moderate') {
                $matchedClues[] = 'Moderate sheep neck';
            }

            if (in_array($panel['bio-sheep-tail-select'] ?? '', ['broad', 'notable'], true)) {
                $femaleScore += 1;
                $matchedClues[] = 'Broad rump or tail';
            } elseif (($panel['bio-sheep-tail-select'] ?? '') === 'tight') {
                $matchedClues[] = 'Tight rump or tail';
            } elseif (($panel['bio-sheep-tail-select'] ?? '') === 'medium') {
                $matchedClues[] = 'Moderate rump or tail';
            }
        } elseif ($type === 'pig') {
            if (in_array($panel['bio-pig-body-select'] ?? '', ['broad', 'very broad'], true)) {
                $maleScore += 1;
                $matchedClues[] = 'Broad pig frame';
            } elseif (($panel['bio-pig-body-select'] ?? '') === 'fine') {
                $femaleScore += 1;
                $matchedClues[] = 'Fine pig frame';
            } elseif (($panel['bio-pig-body-select'] ?? '') === 'balanced') {
                $matchedClues[] = 'Balanced pig frame';
            }

            if (in_array($panel['bio-pig-belly-select'] ?? '', ['heavy', 'rounded'], true)) {
                $femaleScore += 2;
                $matchedClues[] = 'Rounded belly line';
            } elseif (($panel['bio-pig-belly-select'] ?? '') === 'tight') {
                $maleScore += 1;
                $matchedClues[] = 'Tight belly line';
            } elseif (($panel['bio-pig-belly-select'] ?? '') === 'flat') {
                $matchedClues[] = 'Flat belly line';
            }

            if (in_array($panel['bio-pig-head-select'] ?? '', ['thick', 'heavy'], true)) {
                $maleScore += 1;
                $matchedClues[] = 'Heavy snout or head';
            } elseif (($panel['bio-pig-head-select'] ?? '') === 'fine') {
                $femaleScore += 1;
                $matchedClues[] = 'Fine snout or head';
            } elseif (($panel['bio-pig-head-select'] ?? '') === 'moderate') {
                $matchedClues[] = 'Moderate snout or head';
            }
        } elseif ($type === 'other') {
            if (in_array($panel['bio-other-frame-select'] ?? '', ['broad', 'very broad'], true)) {
                $maleScore += 1;
                $matchedClues[] = 'Broader general frame';
            } elseif (($panel['bio-other-frame-select'] ?? '') === 'fine') {
                $femaleScore += 1;
                $matchedClues[] = 'Finer general frame';
            } elseif (($panel['bio-other-frame-select'] ?? '') === 'balanced') {
                $matchedClues[] = 'Balanced general frame';
            }

            if (in_array($panel['bio-other-proportion-select'] ?? '', ['compact', 'robust'], true)) {
                $maleScore += 1;
                $matchedClues[] = 'Compact or robust proportion';
            } elseif (($panel['bio-other-proportion-select'] ?? '') === 'elongated') {
                $femaleScore += 1;
                $matchedClues[] = 'Elongated proportion';
            } elseif (($panel['bio-other-proportion-select'] ?? '') === 'balanced') {
                $matchedClues[] = 'Balanced proportion';
            }

            if (in_array($panel['bio-other-traits-select'] ?? '', ['marked', 'prominent'], true)) {
                $maleScore += 1;
                $matchedClues[] = 'Marked visible traits';
            } elseif (in_array($panel['bio-other-traits-select'] ?? '', ['none', 'small'], true)) {
                $femaleScore += 1;
                $matchedClues[] = 'Few visible traits';
            } elseif (($panel['bio-other-traits-select'] ?? '') === 'moderate') {
                $matchedClues[] = 'Moderate visible traits';
            }
        }

        $difference = $maleScore - $femaleScore;
        $strongSupport = count($matchedClues);

        if (abs($difference) < 2 || $strongSupport < 2) {
            return [
                'sex' => 'Undetermined',
                'detected_gender' => 'Undetermined',
                'matched_clues' => array_slice($matchedClues, 0, 6),
                'conflicting_clues' => array_slice($conflictingClues, 0, 6),
                'explanation' => 'The selected clues are too weak or too mixed to determine sex reliably.',
            ];
        }

        $sex = $difference > 0 ? 'Male' : 'Female';
        $detectedGender = match ($type) {
            'poultry' => $sex === 'Male' ? 'Rooster' : 'Hen',
            'bovin' => $sex === 'Male' ? 'Bull' : 'Cow',
            'sheep' => $sex === 'Male' ? 'Ram' : 'Ewe',
            'pig' => $sex === 'Male' ? 'Boar' : 'Sow',
            'other' => $sex,
            default => $sex,
        };

        $explanation = $sex === 'Male'
            ? 'The strongest cues favor male: ' . implode(', ', array_slice($matchedClues, 0, 3))
            : 'The strongest cues favor female: ' . implode(', ', array_slice($matchedClues, 0, 3));

        return [
            'sex' => $sex,
            'detected_gender' => $detectedGender,
            'matched_clues' => array_slice($matchedClues, 0, 6),
            'conflicting_clues' => array_slice($conflictingClues, 0, 6),
            'explanation' => $explanation,
        ];
    }

    /**
     * @param array{body:string,neck:string,secondary:string,genital:string,behavior:string,panel_data:array<string,string>} $observations
     * @param array{sex:string,detected_gender:string,explanation:string} $analysis
     */
    private function buildSeedMaterial(string $type, array $observations, array $analysis): string
    {
        $normalizedObservations = $observations;
        $panelData = $normalizedObservations['panel_data'] ?? [];
        if (is_array($panelData)) {
            ksort($panelData);
            $normalizedObservations['panel_data'] = $panelData;
        }

        $payload = [
            'type' => $type,
            'observations' => $normalizedObservations,
            'analysis' => $analysis,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function generateSyntheticDnaSequence(string $type, array $observations, array $analysis, string $seedMaterial): string
    {
        $sex = $analysis['sex'];
        $prefix = $this->getMotif($type, $sex, 'prefix');
        $suffix = $this->getMotif($type, $sex, 'suffix');
        $targetLength = 120 + (hexdec(substr(hash('sha256', $seedMaterial . '|length'), 0, 2)) % 31);

        $orderedSelections = $this->getOrderedSelectionMap($type, $observations);
        $selectionSignature = '';

        foreach ($orderedSelections as $field => $value) {
            if ($value === '') {
                continue;
            }

            $chunkLength = 4 + (hexdec(substr(hash('sha256', $field . '|' . $value), 0, 1)) % 4);
            $selectionSignature .= $this->hashToDna($type . '|' . $sex . '|' . $field . '|' . $value, $chunkLength);
        }

        $bodyLength = max(0, $targetLength - strlen($prefix) - strlen($suffix));
        if (strlen($selectionSignature) > $bodyLength) {
            $selectionSignature = substr($selectionSignature, 0, $bodyLength);
        }

        $fillerLength = max(0, $bodyLength - strlen($selectionSignature));
        $filler = $this->hashToDna($seedMaterial . '|filler', $fillerLength);

        return $prefix . $selectionSignature . $filler . $suffix;
    }

    private function getMotif(string $type, string $sex, string $position): string
    {
        $motifs = [
            'poultry' => [
                'Male' => ['prefix' => 'ATGGCT', 'suffix' => 'GCTTGA'],
                'Female' => ['prefix' => 'TTAACC', 'suffix' => 'CCGTAA'],
                'Undetermined' => ['prefix' => 'CGATTA', 'suffix' => 'AAGCTA'],
            ],
            'bovin' => [
                'Male' => ['prefix' => 'ATGCGT', 'suffix' => 'GGCCTA'],
                'Female' => ['prefix' => 'TTCGAA', 'suffix' => 'CCTTGA'],
                'Undetermined' => ['prefix' => 'GATCGA', 'suffix' => 'TAACCG'],
            ],
            'sheep' => [
                'Male' => ['prefix' => 'ATGCAA', 'suffix' => 'GGATTA'],
                'Female' => ['prefix' => 'TTACGA', 'suffix' => 'CGAATC'],
                'Undetermined' => ['prefix' => 'CGTTAA', 'suffix' => 'ATCGGA'],
            ],
            'pig' => [
                'Male' => ['prefix' => 'ATGGGA', 'suffix' => 'GTTACC'],
                'Female' => ['prefix' => 'TTAAGA', 'suffix' => 'CCTGAA'],
                'Undetermined' => ['prefix' => 'GGCATA', 'suffix' => 'TAAGCC'],
            ],
            'other' => [
                'Male' => ['prefix' => 'ATGCGA', 'suffix' => 'GATCCA'],
                'Female' => ['prefix' => 'TTCGTA', 'suffix' => 'CCATGA'],
                'Undetermined' => ['prefix' => 'CGATGC', 'suffix' => 'ATGCCA'],
            ],
        ];

        return $motifs[$type][$sex][$position] ?? 'ATGC';
    }

    private function hashToDna(string $seedMaterial, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $bases = ['A', 'C', 'G', 'T'];
        $sequence = '';
        $counter = 0;

        while (strlen($sequence) < $length) {
            $hash = hash('sha256', $seedMaterial . '|' . $counter);
            $counter++;

            $hashLength = strlen($hash);
            for ($index = 0; $index < $hashLength && strlen($sequence) < $length; $index++) {
                $sequence .= $bases[hexdec($hash[$index]) % 4];
            }
        }

        return $sequence;
    }

    /**
     * @param array{body:string,neck:string,secondary:string,genital:string,behavior:string,panel_data:array<string,string>} $observations
     * @return array<string,string>
     */
    private function getOrderedSelectionMap(string $type, array $observations): array
    {
        $panel = $observations['panel_data'] ?? [];

        $base = [
            'body' => $observations['body'] ?? '',
            'neck' => $observations['neck'] ?? '',
            'secondary' => $observations['secondary'] ?? '',
            'genital' => $observations['genital'] ?? '',
            'behavior' => $observations['behavior'] ?? '',
        ];

        $typeSpecific = match ($type) {
            'poultry' => [
                'poultry_comb' => $panel['bio-poultry-comb-select'] ?? '',
                'poultry_wattle' => $panel['bio-poultry-wattle-select'] ?? '',
                'poultry_tail' => $panel['bio-poultry-tail-select'] ?? '',
            ],
            'bovin' => [
                'bovin_horns' => $panel['bio-bovin-horns-select'] ?? '',
                'bovin_neck' => $panel['bio-bovin-neck-select'] ?? '',
                'bovin_pelvis' => $panel['bio-bovin-pelvis-select'] ?? '',
            ],
            'sheep' => [
                'sheep_horn' => $panel['bio-sheep-horn-select'] ?? '',
                'sheep_neck' => $panel['bio-sheep-neck-select'] ?? '',
                'sheep_tail' => $panel['bio-sheep-tail-select'] ?? '',
            ],
            'pig' => [
                'pig_body' => $panel['bio-pig-body-select'] ?? '',
                'pig_belly' => $panel['bio-pig-belly-select'] ?? '',
                'pig_head' => $panel['bio-pig-head-select'] ?? '',
            ],
            'other' => [
                'other_frame' => $panel['bio-other-frame-select'] ?? '',
                'other_proportion' => $panel['bio-other-proportion-select'] ?? '',
                'other_traits' => $panel['bio-other-traits-select'] ?? '',
            ],
            default => [],
        };

        return array_merge($base, $typeSpecific);
    }
}
