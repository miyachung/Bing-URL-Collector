<?php
/* 

@Bing URL Collector [ + Proxy Support ] 
@Code & Logic by Miyachung
@Contact : miyachung@hotmail.com

*/
error_reporting(E_ALL ^ E_NOTICE);
ini_set('max_execution_time',0);

$processNumber = process();


printf('[PROC-%s] Enter a text to perform a bing search ; %s',$processNumber,PHP_EOL);
printf("[PROC-%s]: ",$processNumber);

$searchText = fgets(STDIN);
$searchText = str_replace("\r\n","",$searchText);
$searchText = trim($searchText);

printf('[PROC-%s] Enter a page number to continuous scan for each search text (default: 1); %s',$processNumber,PHP_EOL);
printf("[PROC-%s]: ",$processNumber);

$pageNum  = fgets(STDIN);
$pageNum  = str_replace("\r\n","",$pageNum);
$pageNum  = trim($pageNum);

printf('[PROC-%s] Enter a proxy list (Fill empty if you would not like to use proxy,that might cause a microsoft ban); %s',$processNumber,PHP_EOL);
printf("[PROC-%s]: ",$processNumber);

$proxyList  = fgets(STDIN);
$proxyList  = str_replace("\r\n","",$proxyList);
$proxyList  = trim($proxyList);

printf('[PROC-%s] Thread number; %s',$processNumber,PHP_EOL);
printf("[PROC-%s]: ",$processNumber);

$threadNumber  = fgets(STDIN);
$threadNumber  = str_replace("\r\n","",$threadNumber);
$threadNumber  = trim($threadNumber);

/* Call Class */
$search = new bingCollector($searchText,$pageNum,$proxyList,$threadNumber,$processNumber);



class bingCollector
{

	private $toSearch = NULL;
	private $pageNum = NULL;
	private $proxyList = NULL;
	private $threadNum = NULL;
	private $useProxy = 1;

	private $collectLinksFile = 'bing_links.txt';
	private $errorList   = ['EMPTY ARGUMENT CAN NOT BE PASSED IN!',
						 	'STRING VAR CAN NOT BE PASSED IN PAGE NUMBER!',
						 	'PROXY LIST FILE DOES NOT EXIST!',
						 	'STRING VAR CAN NOT BE PASSED IN THREAD NUMBER!'
						 	];
	private $infoList 	 = ['Creating duplicated keywords to get more results..',
							'Creating bing links  to add multicurl..',
							'Checking if proxy is enabled..',
							'PROXY ENABLED',
							'PROXY DISABLED',
							'Posting bing links to multicurl..',
							'All threads are done! exit..',
							];


	private $bingLinks 	 = [];
	private $lineBreak 	 = PHP_EOL;
	private $prefix_info = '[INFO]';
	private $prefix_proc = NULL;
	private $bingREGEX   = '@<h2><a href="(.*?)" h=@si';


	public function __construct($textTosearch,$numberofPages=1,$listofProxy,$numberofThread=5,$processNumber)
	{
		$this->prefix_proc = sprintf('[PROC-%s]',$processNumber);
		if(empty($textTosearch)) die($this->prefix_proc.$this->prefix_info.$this->lineBreak.$this->errorList[0]);
		if(empty($numberofPages)) $numberofPages = 1; elseif(!is_numeric($numberofPages)) die($this->prefix_proc.$this->prefix_info.$this->lineBreak.$this->errorList[1]);
		if(empty($numberofThread)) $numberofThread = 1; elseif(!is_numeric($numberofThread)) die($this->prefix_proc.$this->prefix_info.$this->lineBreak.$this->errorList[2]);

		$this->threadNum = $numberofThread;

		if(empty($listofProxy)) $this->useProxy = 0;
		if($this->useProxy){if(!file_exists($listofProxy)) die($this->prefix_proc.$this->prefix_info.$this->lineBreak.$this->errorList[2]);$this->proxyList= array_map('trim',file($listofProxy));}
		# Make definitions	 -
		$this->pageNum  = $numberofPages;
		$this->toSearch = $textTosearch;
		# Call worker -
		$this->worker();
	}

	private function worker()
	{
		$this->writeINFO(1);
		$this->loop_sleep(100000);
		$keywords 		= $this->duplicate_searchText();
		print_r($keywords);

		$this->writeINFO(2);
		$this->loop_sleep(100000);
		$this->createBingLinks($keywords);
		print_r($this->bingLinks);

		$this->writeINFO(3);
		$this->loop_sleep(100000);

		if($this->useProxy){ $this->writeINFO(4); print_r($this->proxyList);} else $this->writeINFO(5);
		$this->writeINFO(6);
		$this->loop_sleep(100000);
		if(file_exists($this->collectLinksFile)) unlink($this->collectLinksFile);

		$this->multiCurlPerform();

	}

	private function multiCurlPerform()
	{
		$window  = array_chunk($this->bingLinks, $this->threadNum);
		$multi   = curl_multi_init();

		$hostOnly = [];
		$linkCounter = 0;
		$jobCounter = 0;
		$proxyFailCounter = 0;
		$proxySuccessCounter = 0;
		$totalJob   = count($this->bingLinks);
		foreach($window as $urls)
		{
			foreach($urls as $i => $link)
			{
				$curl[$i] = curl_init();
				curl_setopt_array($curl[$i], [
					CURLOPT_URL => $link,
					CURLOPT_FOLLOWLOCATION => 1,
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_SSL_VERIFYPEER => 0,
					CURLOPT_SSL_VERIFYHOST => 0,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_TIMEOUT => 15,
				]);
				if($this->useProxy) curl_setopt($curl[$i],CURLOPT_PROXY,$this->proxyList[array_rand($this->proxyList)]);
				curl_multi_add_handle($multi, $curl[$i]);
			}

			do
			{
				while(($run = curl_multi_exec($multi,$running)) === CURLM_CALL_MULTI_PERFORM);
				if($run != CURLM_OK) break;

				curl_multi_select($multi);


				while($done = curl_multi_info_read($multi))
				{
					++$jobCounter;
					$output = curl_multi_getcontent($done['handle']);
					if($done['result'] === 0 && preg_match($this->bingREGEX,$output))
					{
						++$proxySuccessCounter;
						$proxy  = curl_getinfo($done['handle'])['primary_ip'].':'.curl_getinfo($done['handle'])['primary_port'];

						if(preg_match_all($this->bingREGEX,$output,$linksOut))
						{

							foreach($linksOut[1] as $totalLink)
							{
								$parse_url = parse_url($totalLink);
								$host 	   = str_replace("www.","",$parse_url['host']);
								if(!in_array($host, $hostOnly))
								{
									++$linkCounter;
									array_push($hostOnly,$host);
									$this->writeInstantToFile($totalLink);
								}
							}

						} 
				
					}else{++$proxyFailCounter;}
					if($this->useProxy) printf("%sCollected URLS: %s,Jobs Done: %s / %s,Proxy Failed: %s,Success: %s\r",$this->prefix_info,$linkCounter,$jobCounter,$totalJob,$proxyFailCounter,$proxySuccessCounter);
					else 
						printf("%sCollected URLS: %s,Jobs Done: %s / %s,Success: %s\r",$this->prefix_info,$linkCounter,$jobCounter,$totalJob,$proxySuccessCounter);
					curl_multi_remove_handle($multi, $done['handle']);
				}

			}while($running > 0);
		}
		print($this->lineBreak);
		$this->loop_sleep(100000);
		$this->writeINFO(7);
	}

	private function duplicate_searchText()
	{
		$random1 = range('a','z');
		$random2 = range('A','Z');
		$keywords = [];

		for($i = 0; $i < 10; ++$i)
		{
			array_push($keywords,urlencode($this->toSearch.' '.$random1[array_rand($random1)].$random1[array_rand($random1)].$random1[array_rand($random1)]));
			array_push($keywords,urlencode($this->toSearch.' '.$random2[array_rand($random2)].$random2[array_rand($random2)].$random2[array_rand($random2)]));
		}

		return $keywords;

	}

	private function createBingLinks($keywords)
	{
		foreach($keywords as $j => $tosearch)
		{
			$x = 1;
			for($i = 0; $i <= $this->pageNum; ++$i)
			{
				array_push($this->bingLinks,'https://www.bing.com/search?q='.$tosearch.'&first='.$x.'&setlang=en-us&count=100');
				if($i < 2) $x+=50-5; else $x+=50;
			}
		}
	}

	private function writeINFO($case)
	{
		switch($case)
		{
			case 1:
				print($this->lineBreak.$this->prefix_info.$this->infoList[0].$this->lineBreak);
			break;

			case 2:
				print($this->lineBreak.$this->prefix_info.$this->infoList[1].$this->lineBreak);
			break;
			case 3:
				print($this->lineBreak.$this->prefix_info.$this->infoList[2].$this->lineBreak);
			break;
			case 4:
				print($this->lineBreak.$this->prefix_info.$this->infoList[3].$this->lineBreak);
			break;
			case 5:
				print($this->lineBreak.$this->prefix_info.$this->infoList[4].$this->lineBreak);
			break;
			case 6:
				print($this->lineBreak.$this->prefix_info.$this->infoList[5].$this->lineBreak);
			break;
			case 7:
				print($this->lineBreak.$this->prefix_info.$this->infoList[6].$this->lineBreak);
			break;
		}
	}
	private function loop_sleep($seconds)
	{
		for($i = 0 ; $i < 15; ++$i)
		{
			usleep($seconds);
			print '.';
		}
		print $this->lineBreak;
	}
	private function writeInstantToFile($link)
	{
		$fopen = fopen($this->collectLinksFile, 'ab');
		if(flock($fopen,LOCK_EX))
		{
			fwrite($fopen,$link.$this->lineBreak);
		}
		flock($fopen,LOCK_UN);
		fclose($fopen);
	}

}
function process()
{
	$process_id = getmypid();
	return $process_id;
}
