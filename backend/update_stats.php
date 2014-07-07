<?php

include_once __DIR__ . "/../api/1.2/libs/DB.php";
$conn = new APIDB($dbhost, $dbuser, $dbpass, $dbname);

if(count($argv) == 1) {
	echo "Required arg: <counters|isps>\n";
	exit(1);
}

if ($argv[1] == 'counters') {

	$result = $conn->query(" select count(*) from urls", array());
	$row = $result->fetch_row();

	$stats = array(
		'urls_reported' => $row[0],
		);

	$result = $conn->query("select count(distinct urlid) from results",array());
	$row = $result->fetch_row();
	$stats['urls_tested'] = $row[0];

	$result = $conn->query("select count(distinct urlid) from results where status = 'blocked'", array());
	$row = $result->fetch_row();
	$stats['blocked_sites_detected'] = $row[0];

	$result = $conn->query("select count(distinct urlid) from results where status = 'blocked' and filter_level in ('','default')", array());
	$row = $result->fetch_row();
	$stats['blocked_sites_detected_default_filter'] = $row[0];

	print_r($stats);

	foreach($stats as $name => $value) {
		$conn->query(
			"replace into stats_cache (name, value) values (?,?)",
			array($name, $value)
			);
	}

	$conn->commit();

} elseif ($argv[1] == 'isps') {

	$rs = $conn->query("select network_name, status, count(*) ct
	from url_latest_status
	inner join isps on (isps.name = network_name)
	where show_results = 1
	group by network_name, status
	order by network_name, status", array());

	$row = $rs->fetch_assoc();
	
	while ($row) {
		$last = $row['network_name'];
		$out= array();
		# primitive grouping by ISP
		do {
			if (!in_array($row['status'], array('ok','blocked','timeout','error','dnsfail'))) {
				continue;
			}
			$out[ $row['status'] ] = $row['ct'];
			@$out['total'] += $row['ct'];
			$row = $rs->fetch_assoc();
		} while ($row && $row['network_name'] == $last);

		$conn->query("replace into isp_stats_cache(network_name, ok, blocked, timeout, error, dnsfail, total)
		values (?,?,?,?,?,?,?)", 
		array($last, 
			@$out['ok'] or 0,
			@$out['blocked'] or 0,
			@$out['timeout'] or 0,
			@$out['error'] or 0,
			@$out['dnsfail'] or 0,
			@$out['total'] or 0
			)
		);

	} while ($row);
	
	$conn->commit();
}
