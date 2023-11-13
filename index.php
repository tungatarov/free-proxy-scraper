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

function ping($proxy) {
    if (!$fp = fsockopen($proxy['ip'], $proxy['port'], $errno, $errstr, 10))
        return false;

    fclose($fp);
    return true;
}

function parallel_map(callable $fn, array $items)
{
    $childPids = [];
    $result = [];
    foreach ($items as $i => $item) {
        $newPid = pcntl_fork();
        if ($newPid == -1) {
            die('can\'t fork the process');
        } elseif ($newPid) {
            $childPids[] = $newPid;
            if ($i == count($items) - 1) {
                foreach ($childPids as $childPid) {
                    pcntl_waitpid($childPid, $status);
                    $sharedId = shmop_open($childPid, 'a', 0, 0);
                    $sharedData = shmop_read($sharedId, 0, shmop_size($sharedId));
                    $result[] = unserialize($sharedData);
                    shmop_delete($sharedId);
                    if (PHP_MAJOR_VERSION < 8)
                        shmop_close($sharedId);
                }
            }
        } else {
            $myPid = getmypid();
            $funcResult = $fn($item);
            $sharedData = serialize($funcResult);
            $sharedId = shmop_open($myPid, 'c', 644, strlen($sharedData));
            shmop_write($sharedId, $sharedData, 0);
            exit(0);
        }
    }
    return $result;
}

function cacheFactory(callable $fn, $path, $seconds = 3600)
{
    return function () use ($fn, $path, $seconds) {
        $args = func_get_args();
        $file = $path . '/' . md5(serialize($args));
        if (file_exists($file)) {
            $content = unserialize(file_get_contents($file));
            if ($content['endTime'] > time())
                return $content['value'];

            unlink($file);
        }
        $value = call_user_func_array($fn, $args);
        $content['value'] = $value;
        $content['endTime'] = time() + $seconds;
        file_put_contents($file, serialize($content));
        return $value;
    };
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
                    'ip' => base64_decode(
                        clearJson($row->eq(0)->text())),
                    'port' => $row->eq(1)->text(),
                    'protocol' => $row->eq(2)->text(),
                    'anonymity' => $row->eq(6)->text()
                ];
            }
        );
    };
}

function checkProxyFactory($url): Closure
{
    return function ($proxy) use ($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PROXY,
            "{$proxy['protocol']}://{$proxy['ip']}:{$proxy['port']}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status >=200 && $status < 300;
    };
}

function getActiveProxiesFactory(callable $checkProxy): Closure
{
    return function ($proxies) use ($checkProxy) {
        return filter(function ($proxy) use ($checkProxy) {
            return $checkProxy($proxy);
        }, $proxies);
    };
}

$proxyUrl = 'http://free-proxy.cz/en/';
$cache = cacheFactory('getContent', __DIR__ . '/cache', 60 * 5);
$crawler = crawlerFactory($cache);
$getSiteMaxPageNumber = getSiteMaxPageNumberFactory($crawler, 5);
$getSitePages = getSitePagesFactory($getSiteMaxPageNumber);
$getSitePageProxies = getSitePageProxiesFactory($crawler);
$checkProxy = checkProxyFactory('http://httpbin.org/get');
$getActiveProxies = getActiveProxiesFactory($checkProxy);

$proxies =
    reduce('array_merge',
        parallel_map($getActiveProxies,
            array_chunk(
                array_filter(
                    reduce('array_merge',
                        parallel_map($getSitePageProxies,
                            array_chunk(
                                $getSitePages($proxyUrl), 10)), [])), 10)), []);
