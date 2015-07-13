<?php
class HttpClient
{
	public static function get($url, $json_decode = false)
	{
		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_URL, $url);
			$ret = curl_exec($ch);
			curl_close($ch);
		} else {
			$ret = file_get_contents($url);
		}
		if($json_decode){
			$ret = json_decode($ret, true);
		}
		return $ret;
	}

	public static function post($postUrl, $data = array())
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $postUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

		$result = curl_exec($ch);
		$res = curl_getinfo($ch);
		curl_close($ch);

		if ($result === false) {
			return $result;
		}

		return json_decode(trim($result), true);
	}
}

class Meal
{
	private $token = null;
	private $members = [];
	const MEALS_TYPE_LUNCH = '1';
	const MEALS_TYPE_SUPPER = '2';

	/**
	 * 不点的品类
	 */
	private $exceptDishTypes = [5]; //4小点 5米饭

	/**
	 * 不点的菜品
	 */
	private $exceptDishId = [237, 241, 247, 251, 278, 309]; //237 面饼套餐,241包卷套餐, 247 馍饼套餐, 278 炒饭套餐 251面包套餐 309披萨


	//类似1000023这样的数字
	private $teamId = "你们的teamId"; 

	//团队订餐url，不同团队不一样。类似于http://www.chuiyanxiaochu.com/team/menu/{32位数字字母组合}
	private $tokenUrl = "http://www.chuiyanxiaochu.com/team/menu/{32为数字字母组合}";

	//查询团队成员是否订餐
	private	$viewOrderUrl = "http://www.chuiyanxiaochu.com/action/get_team_order_by_name";

	//订餐列表
	private $dishUrl = "http://www.chuiyanxiaochu.com/action/get_dish_list";

	//点餐URL
	private $orderUrl = "http://www.chuiyanxiaochu.com/action/team_mem_add_order";

	public function __construct()
	{
		$this->members = require_once("meal_config.php");
	}
	/***
	 * 订餐入口
	 * **/
	public function run()
	{
		echo date('Y-m-d H:i:s') . " 开始订餐啦\n\n";
		$dish_array = $this->getDishArray();
		$count = count($dish_array);
		$index = 0;
		foreach($this->members as $member){
			$name = $member['name'];
			echo $name . "开始订餐\n";
			if($dish_name = $this->isBooked($name)){
				echo $name . " 已经订餐，$dish_name,跳过\n";
				continue;
			}
			while(true){
				$index = ($index+1)%$count;
				if($dish_array[$index]['remain_num'] > 0){
					if(isset($member['dish_hot']) && !in_array($dish_array[$index]['dish_hot'], $member['dish_hot'])){
						echo $name . $member["name"] . ":辣度不符，重新订餐\n";
						continue;
					}
					if(isset($member['ids']) && !in_array($dish_array[$index]['id'], $member['ids'])){
						echo $name . $member["name"] . "食品不符，重新订餐\n";
						continue;
					}

					$param = [
						'teamId'    => $this->teamId,
						'name'      => $name,
						'did'       => $dish_array[$index]['did'],
						'token'     => $this->getToken(),
						];
					$result = HttpClient::post($this->orderUrl, $param);
					if($result && $result['rtn'] == 0){
						$dish_array[$index]['remain_num']--;
						echo $name . "订餐成功：" . $dish_array[$index]['name'] ."\n";
					}else{
						echo $name . "订餐失败：" . $result['data']['msg'] . "\n";
					}
					break;
				}
			}
		}
		echo "\n" . date('Y-m-d H:i:s') . " 订餐结束\n\n\n";
	}

	/**
	 * 获取整理后的订餐数组
	 * [
	 * ]
	 */
	public function getDishArray()
	{
		$meals_type = date('H') < 12 ? self::MEALS_TYPE_LUNCH : self::MEALS_TYPE_SUPPER;
		$dishlist = $this->getDishlist(date('Y-m-d'), $meals_type);
		$array = [];
		foreach($dishlist as $dish){
			if($dish['remain_num'] > 0 &&  !in_array($dish['dish_type'], $this->exceptDishTypes) &&  !in_array($dish['id'], $this->exceptDishId)){ //去除小点和米饭
				$array[] = $dish;
			}
		}
		shuffle($array);//打乱数组,防止都是同一口味的菜
		return $array;
	}

	/**
	 * stype 不知道什么意思
	 * 获取菜单列表
	 */
	public function getDishlist($date, $type, $stype=2)
	{
		$params = ['day' => $date, 'type' => $type, 'stype' => $stype, '_' => $this->getTimeStamp()];
		$url = $this->dishUrl . '?' . http_build_query($params);
		$result = HttpClient::get($url, true);
		return $result['data']['list'];
	}
	/**
	 * 是否订餐
	 * name 员工名字
	 * date 日期
	 * meals_type 1午餐 2晚餐
	 */
	public function isBooked($name, $date=null, $meals_type=null)
	{
		if($meals_type === null){
			$meals_type = date('H') < 12 ? self::MEALS_TYPE_LUNCH : self::MEALS_TYPE_SUPPER;
		}
		if($date === null){
			$date = date('Y-m-d');
		}

		$params = ['name' => $name, 'teamId' => $this->teamId, 'slt_day' => $date, '_' => $this->getTimeStamp()];
		$url = $this->viewOrderUrl . '?' . http_build_query($params);
		$result = HttpClient::get($url, true);
		if($result && count($result['data']['list']) > 0){
			$list = $result['data']['list'];
			//meals_type 1åé¤ 2æé¤
			foreach($list as $one){
				if($one['meals_type'] == $meals_type){
					return $one['dish_name'];
				}
			}
			return false;
		}else{
			return false;
		}
	}

	private function getTimestamp()
	{
		return floor(microtime(true)*1000);
	}

	public function getToken()
	{
		if($this->token == null){
			$content = HttpClient::get($this->tokenUrl);
			$pattern = '/(?<=token = \')[0-9A-Za-z]{32}/';
			$matches = array();
			if(preg_match($pattern, $content, $matches)){
				$this->token = $matches[0];
			}
		}
		return $this->token;
	}
}

$meal = new Meal;
$meal->run();
