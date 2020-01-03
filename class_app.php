<?php
//**************************************************************************************************

class App {

	public $siteName = SITE_NAME; // Имя сайта
	public $siteNameU = ""; // Имя сайта без спецсимволов
	public $siteBucket = SITE_BUCKET; // Корзина сайта
	public $siteEmail = SITE_EMAIL; // Email сайта
	public $siteUrl = SITE_URL; // Url сайта
	public $siteImage = ""; // Url логотипа сайта
	public $pages = [["name"=>"", "caption"=>""]]; // Массив страниц сайта
	public $routeUrl = ""; // Маршрут без домена и без GET-параметров
	public $routeParts = []; // Массив частей маршрута без домена и без GET-параметров
	public $pageIndex = 0; // Индекс текущей страницы (в массиве pages)
	public $pageName = ""; // Имя текущей страницы (часть маршрута)
	public $pageFile = ""; // Имя файла текущей страницы
	public $pageCaption = ""; // Наименование текущей страницы
	public $pageTitle = ""; // Заголовок текущей страницы (seo)
	public $pageDescription = ""; // Описание текущей страницы (seo)
	public $pageKeywords = "";
	public $canonicalUrl = ""; // Канонический URL страницы
	public $pdo = null; // Объект PDO
	public $authUser = null; // Авторизованный пользователь

	// Конструктор класса
	function __construct() {
		// Создаётся объект PDO
		$this->pdo = $this->createPDO(DB_HOST, DB_NAME, DB_CHAR, DB_USER, DB_PASS);
	}

	// Создаётся объект PDO
	public function createPDO($db_host, $db_name, $db_char, $db_user, $db_pass) {
		$dsn = "mysql:unix_socket=" . $db_host . ";dbname=" . $db_name . ";charset=" . $db_char;
		$opt  = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => TRUE,
		);
		try {
			return new PDO($dsn, $db_user, $db_pass, $opt);
		}
		catch (PDOException $e) {
			http_response_code(500);
			die($e->getMessage());
		}
	}

	// Инициализируется класс после первичного определения его свойств
	public function init() {
		// Установка внутренней кодировки скрипта
		mb_internal_encoding("UTF-8");
		// Имя сайта без спецсимволов
		$this->siteNameU = preg_replace("/[-:\s]/", "_", $this->siteName);
		// Url логотипа сайта
		$this->siteImage = $this->siteUrl . "/images/logo.png";
		// Определяется маршрут
		$this->routeUrl = trim( explode("?", trim(strtolower($_SERVER["REQUEST_URI"]), "/\\"))[0], "/" );
		// Определяется массив частей маршрута
		$this->routeParts = explode("/", $this->routeUrl);
		// Определяется индекс текущей страницы
		$this->pageIndex = $this->determinePage();
		// Определяется имя текущей страницы
		$this->pageName = $this->pages[$this->pageIndex]["name"];
		// Определяется имя файла текущей страницы
		$this->pageFile = $this->pageName;
		// Определяется наименование текущей страницы
		if ( array_key_exists("caption", $this->pages[$this->pageIndex]) ) {
			$this->pageCaption = $this->pages[$this->pageIndex]["caption"];
		}
		// Заменяется стандарный обработчик ошибок
		$value = substr($_SERVER["HTTP_HOST"], 0, 9) == "localhost" ? 1 : 0; // "localhost:14080"
		ini_set("display_errors", $value);
		ini_set("display_startup_errors", $value);
		register_shutdown_function([$this, "shutdownHandler"]);
		// Проверяется авторизация пользователя
		$this->authUser = $this->authCheck();
	}

	// Определяется индекс текущей страницы
	public function determinePage() {
		// Часть маршрута, определяющая имя текущей страницы
		$routePart = "";
		switch (true) {
			// При использовании в Parsers пропускается часть "parsers"
			case $this->routeParts[0] == "parsers" && isset($this->routeParts[1]) :
				$routePart = $this->routeParts[1];
				break;
			// При использовании в API пропускается часть "api"
			case $this->routeParts[0] == "api" && isset($this->routeParts[1]) :
				$routePart = $this->routeParts[1];
				break;
			default :
				$routePart = $this->routeParts[0];
				break;
		}
		// Определяет 0 страницу текущей, если часть маршрута пустая
		if ( empty($routePart) ) { return 0; }
		// Перебор всех возможных страниц сайта
		$i = 0;
		foreach ( $this->pages as $p ) {
			if ( $routePart == $p["name"] ) {
				return $i;
			}
			$i++;
		}
		// Определяет последнюю страницу текущей, если страница не найдена
		// Будет определена -1 страница текущей, если массив pages пустой
		return count($this->pages) - 1;
	}

	// Запись в log
	public function	log($tableName, $tableID, $action, $note, $raw = null) {
		$sql = "
			INSERT INTO logs
			SET
				Log_User_ID = ?,
				Log_DateTime = NOW(),
				Log_Table_Name = ?,
				Log_Table_ID = ?,
				Log_Action = ?,
				Log_Note = ?,
				Log_RAW = ?
		";
		$params = [$this->authUser["User_ID"], $tableName, $tableID, $action, $note, $raw];
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
	}

	// Заменяется стандарный обработчик ошибок
	public function shutdownHandler() {
		$error = error_get_last();
		if ($error) {
			$sql = "
				INSERT INTO errors
				SET
					Error_User_ID = ?,
					Error_DateTime = NOW(),
					Error_Type = ?,
					Error_Message = ?,
					Error_File = ?,
					Error_Line = ?
			";
			$params = [$this->authUser["User_ID"], $error["type"], $error["message"], $error["file"], $error["line"]];
			// Если записать ошибку в БД невозможно, то записываем в файл
			if ($this->pdo) {
				$stmt = $this->pdo->prepare($sql);
				$stmt->execute($params);
			}
			else {
				error_log("shutdownHandler: " . print_r($params, true));
			}
		}
	}

	// Проверяется авторизация пользователя
	// Возвращает объект с данными о пользователе
	public function authCheck() {
		$cookiesName = $this->siteNameU . "_token";
		if ( isset($_COOKIE[$cookiesName]) ) {
			$sql = "
				SELECT users.*, 
					CONCAT(
						Locality_Name_RU,
						IF( ISNULL(Locality_Area_RU), '', CONCAT(', ',Locality_Area_RU) )
					) AS User_Locality
				FROM tokens
				INNER JOIN users ON Token_User_ID = User_ID
				LEFT JOIN localities ON User_Locality_ID = Locality_ID
				WHERE Token_Status = 'L' AND Token_Value = :Token_Value AND Token_DateTime > NOW()
					AND User_Status = 'L'
				LIMIT 1
			";
			$params = ["Token_Value"=>$_COOKIE[$cookiesName]];
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
			if ( $row = $stmt->fetch() ) {
				// Скрывается часть данных в зависимости от текущей страницы
				if ( !in_array($this->routeParts[0], ["api", "parsers", "sitemap_generate", "test"]) ) {
					unset($row["User_Status"], $row["User_Password"], $row["User_Social_ID"], $row["User_Social_Data"]);
					if ( !in_array($this->pageName, ["profile"]) ) { unset($row["User_Email"]); }
					if ( !in_array($this->pageName, ["profile", "post"]) ) { unset($row["User_Phone"]); }
				}
				return $row;
			}
			else {
				return null;
			}
		}
	}

	// Доступ только для перечисленных Roles или по key
	public function authAccess($roles, $key="") {
		if ( in_array($this->authUser["User_Role"], $roles) ) {
			// Access granted
		}
		elseif ( !empty($_GET["key"]) && $_GET["key"] == $key ) {
			// Access granted
		}
		else {
			// Access denied
			$note = "Access denied: {$this->authUser["User_First_Name"]} {$this->authUser["User_Last_Name"]}";
			$this->log("users", $this->authUser["User_ID"], "access", $note, json_encode($this, JSON_UNESCAPED_UNICODE));
			http_response_code(404);
			exit;
		}
	}

	// Проверка Email
	public function emailCheck($email) {
		return preg_match('~^([a-z0-9_\.-]+)@([a-z0-9_\.-]+)\.([a-z\.]{2,6})$~', $email);
	}

	// Проверка даты
	public function dateCheck($date) {
		//return strtotime($date) !== false;
		return preg_match('/\d{4}\-\d{2}\-\d{2}/', $date) && checkdate(substr($date,5,2), substr($date,8,2), substr($date,0,4));
	}

	// Преобразование ГГГГ-ММ-ДД >>> ДД.ММ.ГГГГ
	public function dateDMY($date) {
		return $date ? substr($date, 8, 2) . "." . substr($date, 5, 2) . "." . substr($date, 0, 4) : "";
	}

	// Преобразование ДД.ММ.ГГГГ, ДД-ММ-ГГГГ, ДД месяц ГГГГ >>> ГГГГ-ММ-ДД
	public function dateYMD($date) {
		// Замена нескольких пробелов на 1 пробел
		$date = $this->stringFilter($date, "d");
		// Разбиение на части
		switch (true) {
			case strpos($date, ".") !== false : $delimiter = "."; break;
			case strpos($date, "-") !== false : $delimiter = "-"; break;
			case strpos($date, " ") !== false : $delimiter = " "; break;
			default : return ""; break;
		}
		$parts = explode($delimiter, $date);
		// Замена названия месяца на число
		$p = mb_strtolower(trim($parts[1]));
		if ( in_array($p, ["январь", "января"]) ) $parts[1] = "1";
		if ( in_array($p, ["февраль", "февраля"]) ) $parts[1] = "2";
		if ( in_array($p, ["март", "марта"]) ) $parts[1] = "3";
		if ( in_array($p, ["апрель", "апреля"]) ) $parts[1] = "4";
		if ( in_array($p, ["май", "мая"]) ) $parts[1] = "5";
		if ( in_array($p, ["июнь", "июня"]) ) $parts[1] = "6";
		if ( in_array($p, ["июль", "июля"]) ) $parts[1] = "7";
		if ( in_array($p, ["август", "августа"]) ) $parts[1] = "8";
		if ( in_array($p, ["сентябрь", "сентября"]) ) $parts[1] = "9";
		if ( in_array($p, ["октябрь", "октября"]) ) $parts[1] = "10";
		if ( in_array($p, ["ноябрь", "ноября"]) ) $parts[1] = "11";
		if ( in_array($p, ["декабрь", "декабря"]) ) $parts[1] = "12";
		$dateYMD = str_pad($parts[2], 4, "0", STR_PAD_LEFT) . "-" . str_pad($parts[1], 2, "0", STR_PAD_LEFT) . "-" . str_pad($parts[0], 2, "0", STR_PAD_LEFT);
		if ($this->dateCheck($dateYMD)) return $dateYMD;
		else return "";
	}

	// Вариант для написания слова ["день", "дня", "дней", "день"] ["day", "days", "days", "days"] => {0,1,2,3} (функция игнорирует дробную часть числа)
	public function numberVariant($n) {
		$n = abs(intval($n));
		if ($n == 1) return 0;
		$m = $n % 10;
		if ($n / 10 % 10 == 1) return 2; // -надцать
		if ($m == 1) return 3; // 21 день days
		if (in_array($m, array(2,3,4))) return 1;
		if (in_array($m, array(5,6,7,8,9,0))) return 2;
		return false;
	}

	// Фильтрация строки, введённой пользователем
	public function stringFilter($string, $filter="shmtdfa", $length=0) {
		// Если не передан параметр $filter, то выполняются фильтры по умолчанию.
		// Фильтры:
		// "s" - strip_tags() - удаление тегов HTML и PHP из строки;
		// "h" - htmlspecialchars_decode() - преобразование специальных HTML-сущностей(&quot; и т.п.) обратно в соответствующие символы;
		// "m" - удаление 3-х и 4-х байтовых символов;
		// "t" - trim() - удаление пробелов в начале и в конце строки;
		// "d" - замена нескольких пробелов на 1 пробел;
		// "f" - преобразование 1-го символа строки в верхний регистр;
		// "a" - добавление пробела после знаков препинания (за исключением d,d и d.d);
		// "’" - замена неправильных апострофов;
		// "u" - замена символов не (латиница или цифра) на дефис;
		// $length - извлечение подстроки длиной $length символов;
		// Test: <span> ыыы QQQ.QQQ 1.5 </span>         &quot;🐥🐥🐥&quot; ааа.ббб
		if ( strpos($filter, "s") !== false ) {
			$string = strip_tags($string);
		}
		if ( strpos($filter, "h") !== false ) {
			$string = htmlspecialchars_decode($string, ENT_QUOTES);
			$string = str_replace("&nbsp;", " ", $string);
			$string = str_replace("&mdash;", "-", $string);
			$string = str_replace("&ndash;", "-", $string);
		}
		if ( strpos($filter, "m") !== false ) {
			// triple-byte sequences   1110xxxx 10xxxxxx * 2
			// quadruple-byte sequence 11110xxx 10xxxxxx * 3
			$utf_3_4_filter = "~([\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3})~"; // Модификатор "u" не ставить, т.к. RegExp работает с байтами, а не с UTF-символами
			// Удаление 3х и 4х байтовых символов
			$string = preg_replace($utf_3_4_filter, " ", $string);
		}
		if ( strpos($filter, "t") !== false ) {
			$string = trim($string);
		}
		if ( strpos($filter, "d") !== false ) {
			$string = preg_replace("~\s+~u", " ", $string);
		}
		if ( strpos($filter, "f") !== false ) {
			$string = mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
		}
		if ( strpos($filter, "a") !== false ) {
			$string = preg_replace("~([^\d\s])\.([^\d\s])~", "$1. $2", $string);
		}
		if ( strpos($filter, "’") !== false ) {
			$string = preg_replace("~(\S)[\"'`“‘´′″](\S)~", "$1’$2", $string);
		}
		if ( $length ) {
			if ( mb_strlen($string) > $length ) {
				$string = mb_substr($string, 0, $length+1) . " ";
				$pos = mb_strrpos($string, " ", -2); // поиск справа со второго символа (правая нумерация начинается с 1, а не с 0)
				$string = mb_substr($string, 0, $pos);
			}
		}
		if ( strpos($filter, "u") !== false ) {
			$string = preg_replace("~[^A-Za-z\d]+~", "-", $string); // после фильтра $length
		}
		return $string;
	}

	// Транслитерация
	public function translit($str, $standart="SEO_CYR_LAT") {
		$translit = [
			"Universal_LAT_CYR" => ["A"=>"А", "a"=>"а", "B"=>"Б", "b"=>"б", "V"=>"В", "v"=>"в", "G"=>"Г", "g"=>"г", "D"=>"Д", "d"=>"д", "E"=>"Е", "e"=>"е", "Yo"=>"Ё", "yo"=>"ё", "Zh"=>"Ж", "zh"=>"ж", "##"=>"Ж", "#"=>"ж", "Z"=>"З", "z"=>"з", "I"=>"И", "i"=>"и", "J"=>"Й", "j"=>"й", "K"=>"К", "k"=>"к", "L"=>"Л", "l"=>"л", "M"=>"М", "m"=>"м", "N"=>"Н", "n"=>"н", "O"=>"О", "o"=>"о", "P"=>"П", "p"=>"п", "R"=>"Р", "r"=>"р", "S"=>"С", "s"=>"с", "T"=>"Т", "t"=>"т", "U"=>"У", "u"=>"у", "F"=>"Ф", "f"=>"ф", "H"=>"Х", "h"=>"х", "X"=>"Х", "x"=>"х", "C"=>"Ц", "c"=>"ц", "Cz"=>"Ц", "cz"=>"ц", "Ch"=>"Ч", "ch"=>"ч", "Sh"=>"Ш", "sh"=>"ш", "W"=>"Ш", "w"=>"ш", "Shh"=>"Щ", "shh"=>"щ", "W''"=>"Щ", "w''"=>"щ", "\"\""=>"Ъ", "\""=>"ъ", "Y"=>"Ы", "y"=>"ы", "''''"=>"Ь", "''"=>"ь", "E''"=>"Э", "e''"=>"э", "Yu"=>"Ю", "yu"=>"ю", "Ju"=>"Ю", "ju"=>"ю", "Ya"=>"Я", "ya"=>"я", "Ja"=>"Я", "ja"=>"я"],
			"SEO_CYR_LAT" => ["А"=>"A", "а"=>"a", "Б"=>"B", "б"=>"b", "В"=>"V", "в"=>"v", "Г"=>"G", "г"=>"g", "Д"=>"D", "д"=>"d", "Е"=>"E", "е"=>"e", "Ё"=>"Yo", "ё"=>"yo", "Ж"=>"Zh", "ж"=>"zh", "З"=>"Z", "з"=>"z", "И"=>"I", "и"=>"i", "Й"=>"J", "й"=>"j", "К"=>"K", "к"=>"k", "Л"=>"L", "л"=>"l", "М"=>"M", "м"=>"m", "Н"=>"N", "н"=>"n", "О"=>"O", "о"=>"o", "П"=>"P", "п"=>"p", "Р"=>"R", "р"=>"r", "С"=>"S", "с"=>"s", "Т"=>"T", "т"=>"t", "У"=>"U", "у"=>"u", "Ф"=>"F", "ф"=>"f", "Х"=>"H", "х"=>"h", "Ц"=>"Ts", "ц"=>"ts", "Ч"=>"Ch", "ч"=>"ch", "Ш"=>"Sh", "ш"=>"sh", "Щ"=>"Shh", "щ"=>"shh", "Ь"=>"", "ь"=>"", "Ы"=>"Y", "ы"=>"y", "Ъ"=>"", "ъ"=>"", "Э"=>"E", "э"=>"e", "Ю"=>"Yu", "ю"=>"yu", "Я"=>"Ya", "я"=>"ya", "Ґ"=>"H", "ґ"=>"h", "Є"=>"E", "є"=>"e", "І"=>"I", "і"=>"i", "Ї"=>"J", "ї"=>"j"],
			"GOST2000_CYR_LAT" => ["А"=>"A", "а"=>"a", "Б"=>"B", "б"=>"b", "В"=>"V", "в"=>"v", "Г"=>"G", "г"=>"g", "Д"=>"D", "д"=>"d", "Е"=>"E", "е"=>"e", "Ё"=>"Yo", "ё"=>"yo", "Ж"=>"Zh", "ж"=>"zh", "З"=>"Z", "з"=>"z", "И"=>"I", "и"=>"i", "Й"=>"J", "й"=>"j", "К"=>"K", "к"=>"k", "Л"=>"L", "л"=>"l", "М"=>"M", "м"=>"m", "Н"=>"N", "н"=>"n", "О"=>"O", "о"=>"o", "П"=>"P", "п"=>"p", "Р"=>"R", "р"=>"r", "С"=>"S", "с"=>"s", "Т"=>"T", "т"=>"t", "У"=>"U", "у"=>"u", "Ф"=>"F", "ф"=>"f", "Х"=>"H", "х"=>"h", "Ц"=>"C", "ц"=>"c", "Ч"=>"Ch", "ч"=>"ch", "Ш"=>"Sh", "ш"=>"sh", "Щ"=>"Shh", "щ"=>"shh", "Ь"=>"'", "ь"=>"'", "Ы"=>"Y", "ы"=>"y", "Ъ"=>"''", "ъ"=>"''", "Э"=>"E", "э"=>"e", "Ю"=>"Yu", "ю"=>"yu", "Я"=>"Ya", "я"=>"ya"],
		];
		if ( !array_key_exists($standart, $translit) ) {
			// Запись в log
			$note = "Unknown standard for transliteration: {$standart}";
			$this->log("trips", 0, "translit", $note);
			return $str;
		}
		$arr = $translit[$standart];
		// Сортировка массива по длине ключей
		if ( strpos($standart, "_LAT_CYR") !== false ) {
			// uksort - заметно тормозит при вызове translit() в цикле
			uksort($arr, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
		}
		// Транслитерация
		$str = str_replace(array_keys($arr), $arr, $str);
		return $str;
	}

	// Определение Trip_Seo_Url для view
	public function seoUrl($text, $redirectCode, $viewID) {
		$seoUrl = $this->translit($text, "SEO_CYR_LAT");
		$seoUrl = $this->stringFilter($seoUrl, "u", 50);
		$seoUrl = trim($seoUrl, "-");
		$seoUrl = mb_strtolower($seoUrl) . "-" . $redirectCode . "-" . $viewID;
		return $seoUrl;
	}

	// Получение значения элемента массива с проверкой существования ключа
	public function array_key_value($array, $key, $value = "") {
		return array_key_exists($key, $array) ? $array[$key] : $value;
	}

}

//**************************************************************************************************
