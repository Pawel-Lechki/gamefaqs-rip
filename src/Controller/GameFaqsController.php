<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpClient\HttpClient;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GameFaqsController extends AbstractController
{
    #[Route('/fetch-games', name: "fetch_games", methods: "GET")]
    public function fetchGames(Request $request): Response
    {
        $platform = $request->query->get('platform');
        if (!$platform) {
            return $this->json(['error' => 'Platform parameter is missing'], Response::HTTP_BAD_REQUEST);
        }

        $httpClient = HttpClient::create([
//            'timeout' => 20,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'Referer' => 'https://gamefaqs.gamespot.com/', // Zaktualizuj, jeśli jest potrzebny
            ],
//            'cookies' => [
//                // Dodaj ciasteczka, jeśli są wymagane
//            ]
        ]);
        $url = "https://gamefaqs.gamespot.com/{$platform}/category/999-all";

        $games = [];
        $page = 0;

        do {
            $page++;
            $response = $httpClient->request('GET', $url . "?page=" . $page);
            if ($response->getStatusCode() !== 200) {
                return $this->json(['error' => 'Failed to fetch page', 'status' => $response->getStatusCode()], Response::HTTP_BAD_REQUEST);
            }

            $content = $response->getContent();
            if (empty($content)) {
                return $this->json(['error' => 'Page content is empty'], Response::HTTP_BAD_REQUEST);
            }

            $dom = new \DOMDocument();
            @$dom->loadHTML($content);
            $xpath = new \DOMXPath($dom);
//            $allLinks = $xpath->query('//a');
//            foreach ($allLinks as $link) {
//                echo "Link: " . $link->getAttribute('href') . " | Text: " . $link->textContent . "\n";
//            }
            $gamesNode = $xpath->query('//td[@class="rtitle"]/a');
            if ($gamesNode->length === 0) {
                // Wyświetl komunikat o braku znalezionych elementów z XPath
                return $this->json(['error' => 'No games found with given XPath selector.'], Response::HTTP_BAD_REQUEST);
            }

            foreach ($gamesNode as $node) {
                $gameUrl = "https://gamefaqs.gamespot.com" . $node->getAttribute('href');
                $gameData = $this->fetchGameDetails($gameUrl);
                if ($gameData) {
                    $games[] = $gameData;
                }
            }
            $hasNextPage = $xpath->query('//a[@class="paginate enabled" and contains(text(), "Next")]')->length > 0;
        } while ($hasNextPage);

        return $this->generateXlsx($games);
//        $decodedData = json_decode($games, true);

//        return $this->json($games);
    }

//    private function fetchGameDetails(string $url): ?array
//    {
//        $httpClient = HttpClient::create();
//        $response = $httpClient->request('GET', $url);
//        $content = $response->getContent();
//
//        $dom = new \DOMDocument();
//        @$dom->loadHTML($content);
//        $xpath = new \DOMXPath($dom);
//
//        $name = $this->getXPathText($xpath, '//h1');
//        $genre = $this->getXPathText($xpath, '//td[contains(text(), "Genre")]/following-sibling::td');
//        $releaseDate = $this->getXPathText($xpath, '//td[contains(text(), "Release Date")]/following-sibling::td');
//        $developer = $this->getXPathText($xpath, '//td[contains(text(), "Developer")]/following-sibling::td');
//        $publisher = $this->getXPathText($xpath, '//td[contains(text(), "Publisher")]/following-sibling::td');
//
//        return [
//            'name' => $name,
//            'genre' => $genre,
//            'release_date' => $releaseDate,
//            'developer' => $developer,
//            'publisher' => $publisher,
//        ];
//    }

    private function cleanHtml(string $html): string
    {
        if (class_exists('tidy')) {
            $tidy = new \tidy();
            $config = [
                'clean' => true,
                'output-xhtml' => true,
                'show-body-only' => true,
                'wrap' => 0
            ];
            $tidy->parseString($html, $config, 'utf8');
            $tidy->cleanRepair();
            return $tidy->value;
        }
        // Jeśli Tidy nie jest dostępny, zwróć oryginalny HTML
        return $html;
    }
    private function fetchGameDetails(string $url): ?array
    {
        $httpClient = HttpClient::create(['timeout' => 20,'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]]);
        $response = $httpClient->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $content = $response->getContent();

        // Oczyszczenie HTML za pomocą Tidy (opcjonalne, jeśli występują błędy HTML)
        $cleanContent = $this->cleanHtml($content);

        $dom = new \DOMDocument();
        @$dom->loadHTML($cleanContent);
        $xpath = new \DOMXPath($dom);

        // XPath do pobrania danych:
        $name = $this->getXPathText($xpath, '//h1[@class="page-title"]');
        $platform = $this->getXPathText($xpath, '//ol[@class="list flex col1 nobg"]//li[1]//b[contains(text(), "Platform")]/following-sibling::a');
        $genre = $this->getXPathText($xpath, '//ol[@class="list flex col1 nobg"]//li[2]//b[contains(text(), "Genre")]/following-sibling::a');
        $developer = $this->getXPathText($xpath, '//ol[@class="list flex col1 nobg"]//li[3]//b[contains(text(), "Developer/Publisher")]/following-sibling::a');
        $releaseDate = $this->getXPathText($xpath, '//ol[@class="list flex col1 nobg"]//li[4]//b[contains(text(), "Release")]/following-sibling::a');

        return [
            'name' => $name,
            'platform' => $platform,
            'genre' => $genre,
            'developer' => $developer,
            'publisher' => $developer,
            'release_date' => $releaseDate,
        ];
    }

    private function getXPathText(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        return $node ? trim($node->textContent) : 'N/A';
    }


    private function generateXlsx(array $games): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Name');
        $sheet->setCellValue('B1', 'Genre');
        $sheet->setCellValue('C1', 'Release Date');
        $sheet->setCellValue('D1', 'Developer');
        $sheet->setCellValue('E1', 'Publisher');

        $row = 2;
        foreach ($games as $game) {
            $sheet->setCellValue('A' . $row, $game['name']);
            $sheet->setCellValue('B' . $row, $game['genre']);
            $sheet->setCellValue('C' . $row, $game['release_date']);
            $sheet->setCellValue('D' . $row, $game['developer']);
            $sheet->setCellValue('E' . $row, $game['publisher']);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = 'games.xlsx';
        $temp_file = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($temp_file);

        return $this->file($temp_file, $fileName, ResponseHeaderBag::DISPOSITION_INLINE);
    }
}