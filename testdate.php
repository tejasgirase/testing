<?php
date_default_timezone_set('Asia/Calcutta');
$dtime = new DateTime("now");
$daet = $dtime->format("D M d Y H:i:s \G\M\TO (T)");
$timestart    = explode (' ', microtime());
$timeInSecond = substr($timestart[0],1,4)."Z";
// echo date('c',strtotime($daet));
$insert_ts = preg_replace('/\+.*/',$timeInSecond,date('c',strtotime($daet)));
echo $insert_ts;
// echo date('c');
?>



<!DOCTYPE html>
<html>
<head>
	<title></title>

</head>
<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.2/jquery.min.js"></script>
<script type="text/javascript">
	$(document).ready(function(){
		var sdate = new Date($("#inputval").val() * 1000);
		console.log(JSON.stringify(new Date()));
		console.log(sdate);

	});
</script>
<body>
<!-- <input id="inputval" type="text" value='<?php echo $test; ?>'> -->
</body>
</html>