<?php

use Symfony\Component\DomCrawler\Crawler;

require_once __DIR__ . '/vendor/autoload.php';

function first($array) {
    return reset($array);
}

function reduce(callable $fn, $array, $initial = null) {
    return array_reduce($array, $fn, $initial);
}

function filter(callable $fn, $array, $mode = 0) {
    return array_filter($array, $fn, $mode);
}

function clearJson($jsonData) {
    return preg_match('~\"(.*)\"~', $jsonData, $matches) ? $matches[0] : '';
}

function getContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: */*',
        'Accept-Language: ru,ru-RU;q=0.9,en;q=0.8',
        'Connection: keep-alive',
        'Content-Type: application/json; charset=UTF-8',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Accept-Encoding: *',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function crawlerFactory(callable $getContent): Closure
{
    return function ($url) use ($getContent) {
        return new Crawler($getContent($url));
    };
}

function getSiteMaxPageNumberFactory(callable $crawler, int $maxLimit): Closure
{
    return function ($url) use ($crawler, $maxLimit) {
        return min(
            max(
                first($crawler($url)
                    ->filter('.paginator a:nth-last-of-type(2)')
                    ->each(function (Crawler $a) { return intval($a->text()); })
                ), 1
            ), $maxLimit
        );
    };
}

function getSitePagesFactory(callable $getSiteMaxPageNumber): Closure
{
    return function ($forumUrl) use ($getSiteMaxPageNumber) {
        return array_map(function ($number) use ($forumUrl) {
            return $forumUrl . ($number > 1 ? 'proxylist/main/' . $number : '');
        }, range(1, $getSiteMaxPageNumber($forumUrl)));
    };
}

function getSitePageProxiesFactory(callable $crawler): Closure
{
    return function ($url) use ($crawler) {
        return $crawler($url)
            ->filter('table#proxy_list tbody tr')
            ->each(function (Crawler $node) {
                $row = $node->filter('td');
                if ($row->count() <= 1)
                    return [];

                return [
                    'ip' => base64_decode(clearJson($row->eq(0)->text())),
                    'port' => $row->eq(1)->text(),
                    'protocol' => $row->eq(2)->text(),
                    'anonymity' => $row->eq(6)->text()
                ];
            }
        );
    };
}

$proxyUrl = 'http://free-proxy.cz/en/';
$crawler = crawlerFactory('getContent');
$getSiteMaxPageNumber = getSiteMaxPageNumberFactory($crawler, 5);
$getSitePages = getSitePagesFactory($getSiteMaxPageNumber);
$getSitePageProxies = getSitePageProxiesFactory($crawler);

$proxies =
    array_filter(
        reduce('array_merge',
            array_map($getSitePageProxies,
                $getSitePages($proxyUrl)), []));
