<?php

namespace App\Http\Controllers\Home;

use App\Http\Model\Article;
use App\Http\Model\Category;
use App\Http\Model\Links;
use App\Libs\Predis;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use malkusch\lock\mutex\PHPRedisMutex;
use malkusch\lock\mutex\PredisMutex;
use PDF;
use App\Jobs\test;
use Psr\Http\Message\ResponseInterface;
use QrCode;
use PdfWatermarker\PdfWatermarker;
use DfaFilter\SensitiveHelper;
use App\Libs\Guzzle;
use DB;
use RedisPHP;
use Zipper;
use Common;
use GuzzleHttp\Promise;

class IndexController extends CommonController
{
    private $random;

    public function index(Request $request)
    {

        //会触发observer
        $obj = Article::find(14);
        $obj->art_tag = '苹果';
        $obj->save();

        //不会触发observer
        // Article::where('art_id',14)->update(['art_tag'=>'苹果11']);

        //点击量最高的6篇文章（站长推荐）
        $pics = Article::orderBy('art_view', 'desc')->take(6)->get();

        //图文列表5篇（带分页）
        $data = Article::orderBy('art_time', 'desc')->paginate(5);

        //友情链接
        $links = Links::orderBy('link_order', 'asc')->get();

        return view('home.index', compact('pics', 'data', 'links'));
    }

    public function cate($cate_id)
    {
        //图文列表4篇（带分页）
        $data = Article::where('cate_id', $cate_id)->orderBy('art_time', 'desc')->paginate(4);

        //查看次数自增
        Category::where('cate_id', $cate_id)->increment('cate_view');

        //当前分类的子分类
        $submenu = Category::where('cate_pid', $cate_id)->get();

        $field = Category::find($cate_id);
        return view('home.list', compact('field', 'data', 'submenu'));
    }

    public function article($art_id)
    {
        $field = Article::Join('category', 'article.cate_id', '=', 'category.cate_id')->where('art_id', $art_id)->first();

        //查看次数自增
        Article::where('art_id', $art_id)->increment('art_view');

        $article['pre'] = Article::where('art_id', '<', $art_id)->orderBy('art_id', 'desc')->first();
        $article['next'] = Article::where('art_id', '>', $art_id)->orderBy('art_id', 'asc')->first();

        $data = Article::where('cate_id', $field->cate_id)->orderBy('art_id', 'desc')->take(6)->get();

        return view('home.new', compact('field', 'article', 'data'));
    }

    /**
     * 测试web页面展示pdf，使用loadHTML()方法加载
     * @return mixed
     */
    public function testStreamPdf()
    {
        $html = '<html><head><title>Laravel</title><meta http-equiv=\'Content-Type\' content=\'text/html; charset=utf-8\'/><style>body{  font-family: \'msyh\';  }  @font-face {  font-family: \'msyh\';  font-style: normal;  font-weight: normal;  src: url(http://www.testenv.com/fonts/msyh.ttf) format(\'truetype\');  }</style></head><body><div class=\'container\'><div class=\'content\'><p style=\'font-family: msyh, DejaVu Sans,sans-serif;\'>献给母亲的爱</p><div style=\'font-family: msyh, DejaVu Sans,sans-serif;\' class=\'title\'>Laravel 5中文测试</div><div  class=\'title\'>测试三</div></div></div></body></html>';
        $pdf = PDF::loadHTML($html);
        return $pdf->download();
    }

    /**
     * 测试下载pdf，使用loadView()方法
     * @return mixed
     */
    public function testDownloadPdf()
    {
        // $watermarker = new PdfWatermarker(
        //     $_SERVER['DOCUMENT_ROOT'].'/public/1471231264.pdf', // input
        //     $_SERVER['DOCUMENT_ROOT'].'/public/output.pdf', // output
        //     $_SERVER['DOCUMENT_ROOT'].'/public/new.png', // watermark file
        //     'center', // watermark position (topleft, topright, bottomleft, bottomright, center)
        //     true // set to true - replace original input file
        // );
        // $watermarker->create();
        // die;

        $data = array('name' => '测试');
        return view('invoice', $data);
        $pdf = PDF::loadView('invoice', $data);

        return $pdf->stream('invoice.pdf');
    }

    public function savePic(Request $request)
    {
        $action = $request->input('action', '');
        if ($action == 'save') {
            $picInfo = $request->input('baseimg');

            $streamFileRand = date('YmdHis') . rand(1000, 9999); //图片名
            $picType = '.png';//图片后缀

            $streamFilename = "./public/echarts/" . $streamFileRand . $picType; //图片保存地址
            preg_match('/(?<=base64,)[\S|\s]+/', $picInfo, $picInfoW);//处理base64文本
            //print_r($picInfoW);die;
            file_put_contents($streamFilename, base64_decode($picInfoW[0]));//文件写入

            echo json_encode(['code' => 1, 'info' => $streamFileRand . $picType]);
        }
    }

    public function test()
    {
        \Log::info('test');
        sleep(3);
        pd(11);
        $job = (new test(111))->onQueue('testQueue');
        $this->dispatch($job);
        echo 123;
        die;
    }

    public function qrCode()
    {
        return QrCode::encoding('UTF-8')
            ->size(380)
            ->merge('/public/test.png', .15)
            ->generate('你好，Laravel学院！');
    }

    /**
     * 敏感词检测
     * @throws \DfaFilter\Exceptions\PdsBusinessException
     * @throws \DfaFilter\Exceptions\PdsSystemException
     */
    public function check()
    {
        $path = public_path() . '/' . 'pub_sms_banned_words.txt';
        // $file = file($path);

        $content = '妈的呃呃呃呃呃呃鹅鹅鹅鹅鹅鹅饿';
        $handle = SensitiveHelper::init()->setTreeByFile($path);


        $islegal = $handle->islegal($content);
        var_dump($content, $islegal);

        // 敏感词替换为***为例
        $filterContent = $handle->replace($content, '***');
        var_dump($filterContent);

        // 获取内容中所有的敏感词
        $sensitiveWordGroup = $handle->getBadWord($content);
        // 仅且获取一个敏感词
        $sensitiveWordGroup = $handle->getBadWord($content, 1);
        var_dump($sensitiveWordGroup);
    }

    /**
     * 测试guzzle请求
     * @param Request $request
     */
    public function testGuzzle(Request $request)
    {
        $base_uri = 'http://192.168.90.206';
        $api = '/';
        $headers = ['Accept-Encoding' => 'gzip', 'User-Agent' => '(kingnet oa web server)'];
        $proxy = '';
        $proxy = 'http://192.168.90.252:8888';
        $cookie = '';
        $postData = ['a' => 'c'];
        $multipartData = [
            [
                'name' => 'a',
                'contents' => 'hello'
            ]
        ];
        // $postData = http_build_query($postData);
        $res = Guzzle::post($base_uri, $api, $multipartData, 3, $headers, $proxy);
        p($res, 1);


        $base_uri = 'http://192.168.90.206';
        $api = '/';
        $headers = ['Accept-Encoding' => 'gzip', 'User-Agent' => '(kingnet oa web server)'];
        $proxy = '';
        // $proxy = 'http://192.168.90.252:8888';
        $cookie = ['PHPSESSID' => 'web2~ri5m4tjbi6gk6eeu72ghg27l61'];
        $domain = '192.168.90.206';
        $res = Guzzle::get($base_uri, $api, ['c' => 'd', 'a' => 'b'], $headers, $proxy);
        p($res, 1);


        $base_uri = 'http://127.0.0.1/';
        $api = '/';
        $query = ['c' => 'd', 'a' => 'b'];
        $headers = ['Accept-Encoding' => 'gzip', 'User-Agent' => '(kingnet oa web server)'];
        $proxy = 'http://127.0.0.1:8888';
        $cookie = ['PHPSESSID' => 'web2~ri5m4tjbi6gk6eeu72ghg27l61'];
        $domain = '127.0.0.1';
        // $res = Guzzle::get($base_uri,$api,['c'=>'d','a'=>'b'],$headers,$proxy);
        // p($res,1);
    }

    public function testGuuzle1()
    {
        try {
            $client = new Client([
                'base_uri' => 'http://192.168.90.206:8001',
                'timeout' => 10
            ]);
            $res = $client->get('/t2.php');
            pp($res->getStatusCode(), $res->getBody()->getContents(), $res->getReasonPhrase(), $res->getHeaders(), (string)$res->getBody());
            echo $res->getBody();
        } catch (ConnectException $e) {
            pp(sprintf("[FILE] %s || [LINE] %s || [MSG] %s", $e->getFile(), $e->getLine(), $e->getMessage()));
        } catch (RequestException $e) {
            pp(sprintf("[FILE] %s || [LINE] %s || [MSG] %s", $e->getFile(), $e->getLine(), $e->getMessage()));
        }
        die;

        //guzzle并行异步请求
        $s = microtime(true);
        $client = new Client(['base_uri' => 'http://192.168.90.206:8001']);
        // Initiate each request but do not block
        $promises = [
            'a' => $client->getAsync('/t2.php'),
            'b' => $client->getAsync('/t2.php'),
            'c' => $client->getAsync('/t2.php'),
            'd' => $client->getAsync('/t2.php')
        ];

        // Wait on all of the requests to complete.
        $results = Promise\unwrap($promises);
        // You can access each result using the key provided to the unwrap
        // function.
        echo $results['a']->getBody()->getContents();
        echo $results['b']->getBody()->getContents();
        $e = microtime(true);
        echo sprintf("%.2f", $e - $s);

        //异步请求
        return;
        $client = new Client([
            'base_uri' => 'http://192.168.90.206',
            'timeout' => 10.0
        ]);
        $promise = $client->requestAsync('GET', 'http://192.168.90.206:8001/t2.php');
        $promise->then(
            function (ResponseInterface $res) {
                pd($res->getBody()->getContents());
                echo $res->getStatusCode() . "\n";
            },
            function (RequestException $e) {
                echo $e->getMessage() . "\n";
                echo $e->getRequest()->getMethod();
            }
        );
        $promise->wait();
    }

    /**
     * pdf转图片
     * @throws \Spatie\PdfToImage\Exceptions\PageDoesNotExist
     * @throws \Spatie\PdfToImage\Exceptions\PdfDoesNotExist
     */
    public function pdfToImg()
    {
        ignore_user_abort();
        ini_set('max_execution_time', '0');
        $pathToPdf = '/mnt/hgfs/oa_site/new_src/public/attachment/1471230189.pdf';
        $pathToWhereImageShouldBeStored = '/tmp/pdfToImg/';
        $pdf = new \Spatie\PdfToImage\Pdf($pathToPdf);
        $pages = $pdf->getNumberOfPages();


        for ($i = 1; $i <= $pages; $i++) {
            $pdf->setPage($i)->setResolution(600)->setCompressionQuality(100)->saveImage($pathToWhereImageShouldBeStored);
            echo $i . '==ok<br>';
        }

    }

    /**
     * pdf转html
     */
    public function pdfToHtml()
    {
        $pdf = new \Gufy\PdfToHtml\Pdf('/mnt/hgfs/oa_site/new_src/public/attachment/1471231392.pdf');
        $pages = $pdf->getPages();

        for ($i = 1; $i <= 6; $i++) {
            $html = $pdf->html($i);
            echo $html;
        }

    }

    public function testZip()
    {
        $files = glob('/opt/wwwroot/php_learn/abcd/');
        $res = Zipper::make('/opt/wwwroot/php_learn/abcd/test.zip')->add($files)->close();
        pd($res);
    }

    public function kafkaProduce()
    {

        $config = \Kafka\ProducerConfig::getInstance();
        $config->setMetadataRefreshIntervalMs(10000);
        $config->setMetadataBrokerList('127.0.0.1:9092');
        $config->setBrokerVersion('0.10.0.0');
        $config->setRequiredAck(1);
        $config->setIsAsyn(false);
        $config->setProduceInterval(500);
        $producer = new \Kafka\Producer();

        for ($i = 0; $i < 1; $i++) {
            $result = $producer->send(array(
                array(
                    'topic' => 'test',
                    'value' => 'test1....message.',
                    'key' => '',
                ),
            ));
            pp($result);
        }
    }

    public function testRedisLock()
    {
        if ($this->lock('test', 100)) {
            DB::transaction(function () {
                $res = DB::table('test')->where('id', 1)
                    // ->lockForUpdate()
                    ->first();
                $num = $res->num - 1;
                DB::table('test')->where('id', 1)->update(['num' => $num]);
            });
            $this->unlock('test');
            \Log::info('执行完成');
        } else {
            \Log::info('未获取到锁，请稍后再试');
        }
    }

    public function lock($key, $expire = 0, $type = 'ex')
    {
        $this->random = rand(1, 4294967295);
        return RedisPHP::set($key, $this->random, 'nx', $type, $expire);
    }

    public function unlock($key)
    {
        $val = RedisPHP::get($key);
        if ($val == $this->random) {
            RedisPHP::del($key);
        }
    }

    public function lay()
    {
        return view('layui');
    }
}
