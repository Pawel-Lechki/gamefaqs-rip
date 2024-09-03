<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $httpClient = HttpClient::create();
        $url = "https://gamefaqs.gamespot.com/{$platform}/category/999-all";

        $games = [];
        $page = 0;

        do {
            $page++;
            $response = $httpClient->request('GET', $url . "?page=" . $page);
            $content = $response->getContent();

            $dom = new \DOMDocument();
            @$dom->loadHTML($content);
            $xpath = new \DOMXPath($dom);
            $gamesNode = $xpath->query('//a[@class="log"]');

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
    }

    private function fetchGameDetails(string $url): ?array
    {
        $httpClient = HttpClient::create();
        $response = $httpClient->request('GET', $url);
        $content = $response->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        $xpath = new \DOMXPath($dom);

        $name = $this->getXPathText($xpath, '//h1');
        $genre = $this->getXPathText($xpath, '//td[contains(text(), "Genre")]/following-sibling::td');
        $releaseDate = $this->getXPathText($xpath, '//td[contains(text(), "Release Date")]/following-sibling::td');
        $developer = $this->getXPathText($xpath, '//td[contains(text(), "Developer")]/following-sibling::td');
        $publisher = $this->getXPathText($xpath, '//td[contains(text(), "Publisher")]/following-sibling::td');

        return [
            'name' => $name,
            'genre' => $genre,
            'release_date' => $releaseDate,
            'developer' => $developer,
            'publisher' => $publisher,
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