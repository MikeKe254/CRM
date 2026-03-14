<?php

echo "Rebuilding customer profiles...\n";


/* -------------------------------------------------------
DATABASE CONNECTION
Creates a PDO connection to the MySQL database
------------------------------------------------------- */
function connectDatabase() {

$host = 'localhost';
$db   = 'koma_transactions';
$user = 'koma_trans';
$pass = 'Komaresort@1';

try {

$pdo = new PDO(
"mysql:host=$host;dbname=$db;charset=utf8mb4",
$user,
$pass,
[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

return $pdo;

} catch (Exception $e) {

die("Database connection failed\n");

}

}


/* -------------------------------------------------------
CLEAR PROFILE TABLE
Deletes all previous customer profiles before rebuilding
------------------------------------------------------- */
function clearProfiles($pdo){

$pdo->exec("TRUNCATE TABLE customer_profiles");

}


/* -------------------------------------------------------
TOTAL REVENUE
Calculates total system revenue used for revenue share %
------------------------------------------------------- */
function getTotalRevenue($pdo){

$total_revenue = $pdo->query("
SELECT SUM(amount)
FROM mpesa_payments
")->fetchColumn();

return $total_revenue ?: 1;

}


/* -------------------------------------------------------
GET VALID CUSTOMERS
Returns all unique MSISDN numbers that match valid format
------------------------------------------------------- */
function getValidCustomers($pdo){

$stmt = $pdo->query("
SELECT msisdn
FROM mpesa_payments
WHERE msisdn REGEXP '^[0-9]{12}$'
GROUP BY msisdn
");

return $stmt->fetchAll(PDO::FETCH_COLUMN);

}


/* -------------------------------------------------------
CUSTOMER SPENDING RANK
Ranks customers by total spend and assigns tier/segment
------------------------------------------------------- */
function buildCustomerRanks($pdo,$total_customers){

$stmt = $pdo->query("
SELECT
msisdn,
SUM(amount) spend,
COUNT(*) transactions
FROM mpesa_payments
WHERE msisdn IS NOT NULL
AND msisdn != ''
AND msisdn REGEXP '^[0-9]{12}$'
GROUP BY msisdn
ORDER BY spend DESC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ranks=[];
$rank=0;

foreach ($rows as $r){

$rank++;

$percentile = ($rank / $total_customers) * 100;

$avgSpend = $r['spend'] / $r['transactions'];

if ($avgSpend < 1000) {


$segment = 'Regular';
$tier = 'Silver';


} else {

if ($percentile <= 1) $segment='Whale';
elseif ($percentile <= 5) $segment='VIP';
elseif ($percentile <= 10) $segment='High Value';
elseif ($percentile <= 50) $segment='Regular';
else $segment='Low Value';

if ($percentile <= 1) $tier='Diamond';
elseif ($percentile <= 5) $tier='Platinum';
elseif ($percentile <= 15) $tier='Gold';
elseif ($percentile <= 40) $tier='Silver';
else $tier='Bronze';

}

$ranks[$r['msisdn']] = [
'rank'=>$rank,
'percentile'=>$percentile,
'segment'=>$segment,
'tier'=>$tier
];

}

return $ranks;

}



/* -------------------------------------------------------
IDENTITY ENGINE
Gets first known identity data for the customer
------------------------------------------------------- */
function getIdentityData($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT first_name, gender, reference, short_code
FROM mpesa_payments
WHERE msisdn=?
ORDER BY created_at
LIMIT 1
");

$stmt->execute([$msisdn]);

$r=$stmt->fetch(PDO::FETCH_ASSOC);

return [
'first_name'=>$r['first_name'] ?? null,
'gender'=>$r['gender'] ?? null,
'first_reference'=>$r['reference'] ?? null,
'first_shortcode'=>$r['short_code'] ?? null
];

}


/* -------------------------------------------------------
SPENDING STATISTICS
Calculates total, average, min, max transactions
------------------------------------------------------- */
function getSpendingStats($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT
MIN(created_at) first_tx,
MAX(created_at) last_tx,
SUM(amount) total_spend,
AVG(amount) avg_spend,
MAX(amount) max_spend,
MIN(amount) min_spend,
COUNT(*) total_tx
FROM mpesa_payments
WHERE msisdn=?
");

$stmt->execute([$msisdn]);

return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* -------------------------------------------------------
CUSTOMER AGE ENGINE
Calculates customer age, days since last visit,
visit frequency and spend velocity
------------------------------------------------------- */
function getCustomerAgeStats($first_tx,$last_tx,$total_tx,$total_spend){

$first=new DateTime($first_tx);
$last=new DateTime($last_tx);
$now=new DateTime();

$customer_age_days=$first->diff($now)->days;
$customer_age_months=max(1,floor($customer_age_days/30));
$days_since_last=$last->diff($now)->days;

$visit_frequency=$total_tx/$customer_age_months;
$spend_velocity=$total_spend/$customer_age_months;

return [
'customer_age_days'=>$customer_age_days,
'customer_age_months'=>$customer_age_months,
'days_since_last'=>$days_since_last,
'visit_frequency'=>$visit_frequency,
'spend_velocity'=>$spend_velocity
];

}


/* -------------------------------------------------------
VISIT INTERVAL ENGINE
Calculates average and longest return intervals
------------------------------------------------------- */
function calculateVisitIntervals($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT created_at
FROM mpesa_payments
WHERE msisdn=?
ORDER BY created_at
");

$stmt->execute([$msisdn]);

$dates=$stmt->fetchAll(PDO::FETCH_COLUMN);

$intervals=[];
$longest=0;

for($i=1;$i<count($dates);$i++){

$d1=new DateTime($dates[$i-1]);
$d2=new DateTime($dates[$i]);

$diff=$d1->diff($d2)->days;

$intervals[]=$diff;

if($diff>$longest) $longest=$diff;

}

$avg=count($intervals)?array_sum($intervals)/count($intervals):0;

return [
'avg_interval'=>$avg,
'longest_interval'=>$longest
];

}


/* -------------------------------------------------------
VISIT PATTERN ENGINE
Calculates weekday/weekend and time-of-day ratios
------------------------------------------------------- */
function getVisitPatterns($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT
SUM(CASE WHEN DAYOFWEEK(created_at) BETWEEN 2 AND 6 THEN 1 ELSE 0 END) weekday,
SUM(CASE WHEN DAYOFWEEK(created_at) IN (1,7) THEN 1 ELSE 0 END) weekend,
SUM(CASE WHEN HOUR(created_at) BETWEEN 6 AND 11 THEN 1 ELSE 0 END) morning,
SUM(CASE WHEN HOUR(created_at) BETWEEN 12 AND 16 THEN 1 ELSE 0 END) afternoon,
SUM(CASE WHEN HOUR(created_at) BETWEEN 17 AND 20 THEN 1 ELSE 0 END) evening,
SUM(CASE WHEN HOUR(created_at) >=21 OR HOUR(created_at) <6 THEN 1 ELSE 0 END) night,
COUNT(*) total
FROM mpesa_payments
WHERE msisdn=?
");

$stmt->execute([$msisdn]);

$r=$stmt->fetch(PDO::FETCH_ASSOC);

$total=max(1,$r['total']);

return [
'weekday_ratio'=>$r['weekday']/$total,
'weekend_ratio'=>$r['weekend']/$total,
'morning_ratio'=>$r['morning']/$total,
'afternoon_ratio'=>$r['afternoon']/$total,
'evening_ratio'=>$r['evening']/$total,
'night_ratio'=>$r['night']/$total
];

}


/* -------------------------------------------------------
CHURN ENGINE
Predicts probability of churn based on visit intervals
------------------------------------------------------- */
function calculateChurn($days_since_last,$avg_interval){

$churn_probability=0;

if($avg_interval>0){

$ratio=$days_since_last/$avg_interval;

$churn_probability=min(1,$ratio/3);

}

if($churn_probability>0.7) $risk='High';
elseif($churn_probability>0.4) $risk='Medium';
else $risk='Low';

return [
'churn_probability'=>$churn_probability,
'churn_risk'=>$risk
];

}


/* -------------------------------------------------------
RFM ENGINE
Calculates recency, frequency, monetary scores
------------------------------------------------------- */
function calculateRFM($days_since_last,$total_tx,$avg_spend){

$recency=max(1,5-floor($days_since_last/30));
$frequency=min(5,floor($total_tx/5)+1);
$monetary=min(5,floor($avg_spend/500)+1);

return [
'rfm_recency'=>$recency,
'rfm_frequency'=>$frequency,
'rfm_monetary'=>$monetary,
'rfm_total'=>$recency+$frequency+$monetary
];

}


/* -------------------------------------------------------
VARIANCE ENGINE
Calculates spend variance and standard deviation
------------------------------------------------------- */
function calculateSpendVariance($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT amount
FROM mpesa_payments
WHERE msisdn=?
");

$stmt->execute([$msisdn]);

$amounts=$stmt->fetchAll(PDO::FETCH_COLUMN);

if(count($amounts)<=1) return ['variance'=>null,'std'=>null];

$mean=array_sum($amounts)/count($amounts);

$sum=0;

foreach($amounts as $a){

$sum+=pow($a-$mean,2);

}

$variance=$sum/count($amounts);

return [
'variance'=>$variance,
'std'=>sqrt($variance)
];

}


/* -------------------------------------------------------
PREFERENCE ENGINE
Finds most used reference and shortcode
------------------------------------------------------- */
function getPreferences($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT reference
FROM mpesa_payments
WHERE msisdn=?
GROUP BY reference
ORDER BY COUNT(*) DESC
LIMIT 1
");
$stmt->execute([$msisdn]);
$fav_reference=$stmt->fetchColumn();

$stmt=$pdo->prepare("
SELECT short_code
FROM mpesa_payments
WHERE msisdn=?
GROUP BY short_code
ORDER BY COUNT(*) DESC
LIMIT 1
");
$stmt->execute([$msisdn]);
$shortcode=$stmt->fetchColumn();

return [
'favorite_reference'=>$fav_reference,
'preferred_shortcode'=>$shortcode
];

}

/* -------------------------------------------------------
SPENDING GROWTH ENGINE
Measures whether customer's spending is increasing
------------------------------------------------------- */
function calculateSpendingGrowthRate($pdo,$msisdn){

$stmt=$pdo->prepare("
SELECT amount
FROM mpesa_payments
WHERE msisdn=?
ORDER BY created_at
");

$stmt->execute([$msisdn]);

$amounts=$stmt->fetchAll(PDO::FETCH_COLUMN);

if(count($amounts)<4){
return null;
}

$half=floor(count($amounts)/2);

$first_half=array_slice($amounts,0,$half);
$second_half=array_slice($amounts,$half);

$avg1=array_sum($first_half)/count($first_half);
$avg2=array_sum($second_half)/count($second_half);

if($avg1==0) return 0;

$growth=($avg2-$avg1)/$avg1;

return $growth;

}

/* -------------------------------------------------------
ENGAGEMENT ENGINE
Measures how active and valuable a customer is
------------------------------------------------------- */
function calculateEngagementScore($rfm_total,$visit_frequency,$spend_velocity){

$score=($rfm_total*5)+($visit_frequency*3)+($spend_velocity*0.01);

return $score;

}

/* -------------------------------------------------------
VISIT CONSISTENCY ENGINE
Measures how regularly a customer visits
------------------------------------------------------- */
function calculateVisitConsistency($avg_interval){

if($avg_interval<=0) return 0;

return 100/$avg_interval;

}


/* -------------------------------------------------------
INSERT PROFILE
Inserts computed customer profile into database
------------------------------------------------------- */
function insertCustomerProfile($pdo,$data){

$stmt=$pdo->prepare("
INSERT INTO customer_profiles
(
msisdn,first_name,gender,
first_transaction,last_transaction,
customer_age_days,customer_age_months,days_since_last,
all_time_spend,average_spend,highest_transaction,lowest_transaction,
all_time_transactions,
classification,first_appearance_before_search,
average_return_interval_days,longest_interval_days,
visit_frequency_per_month,spend_velocity_per_month,
spend_variance,spend_std_dev,revenue_share_percent,
customer_rank,top_spender_percentile,spending_segment,loyalty_tier,
lifecycle_stage,churn_risk,churn_probability,
rfm_recency_score,rfm_frequency_score,rfm_monetary_score,rfm_total_score,
predicted_next_visit,predicted_lifetime_value,
spending_growth_rate,engagement_score,visit_consistency_score,
weekday_visit_ratio,weekend_visit_ratio,
morning_visit_ratio,afternoon_visit_ratio,evening_visit_ratio,night_visit_ratio,
favorite_reference,preferred_shortcode,
first_reference,first_shortcode,
most_common_visit_time,most_common_visit_day
)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$stmt->execute($data);

}



/* -------------------------------------------------------
MAIN EXECUTION
------------------------------------------------------- */

$pdo = connectDatabase();

clearProfiles($pdo);

$total_revenue = getTotalRevenue($pdo);

$customers = getValidCustomers($pdo);

$total_customers = count($customers);

echo "Customers detected: $total_customers\n";

$ranks = buildCustomerRanks($pdo,$total_customers);


/* -------------------------------------------------------
PROCESS EACH CUSTOMER
------------------------------------------------------- */

foreach($customers as $msisdn){

$identity=getIdentityData($pdo,$msisdn);

$spending=getSpendingStats($pdo,$msisdn);

if(!$spending) continue;

$age=getCustomerAgeStats(
$spending['first_tx'],
$spending['last_tx'],
$spending['total_tx'],
$spending['total_spend']
);

$intervals=calculateVisitIntervals($pdo,$msisdn);

$patterns=getVisitPatterns($pdo,$msisdn);

$churn=calculateChurn(
$age['days_since_last'],
$intervals['avg_interval']
);

$rfm=calculateRFM(
$age['days_since_last'],
$spending['total_tx'],
$spending['avg_spend']
);

$variance=calculateSpendVariance($pdo,$msisdn);

$prefs=getPreferences($pdo,$msisdn);

$rank = $ranks[$msisdn]['rank'] ?? null;
$percentile = $ranks[$msisdn]['percentile'] ?? null;
$segment = $ranks[$msisdn]['segment'] ?? null;
$tier = $ranks[$msisdn]['tier'] ?? null;

$revenue_share=($spending['total_spend']/$total_revenue)*100;

$predicted_lifetime_value=$spending['avg_spend']*$age['visit_frequency']*12;

$growth_rate = calculateSpendingGrowthRate($pdo,$msisdn);

$engagement_score = calculateEngagementScore(
$rfm['rfm_total'],
$age['visit_frequency'],
$age['spend_velocity']
);

$visit_consistency = calculateVisitConsistency(
$intervals['avg_interval']
);

insertCustomerProfile($pdo,[

$msisdn,
$identity['first_name'],
$identity['gender'],

$spending['first_tx'],
$spending['last_tx'],

$age['customer_age_days'],
$age['customer_age_months'],
$age['days_since_last'],

$spending['total_spend'],
$spending['avg_spend'],
$spending['max_spend'],
$spending['min_spend'],

$spending['total_tx'],

$spending['total_tx']==1?'New':'Returning',
$spending['first_tx'],

$intervals['avg_interval'],
$intervals['longest_interval'],

$age['visit_frequency'],
$age['spend_velocity'],

$variance['variance'],
$variance['std'],
$revenue_share,

$rank,$percentile,$segment,$tier,

'Regular',
$churn['churn_risk'],
$churn['churn_probability'],

$rfm['rfm_recency'],
$rfm['rfm_frequency'],
$rfm['rfm_monetary'],
$rfm['rfm_total'],

null,
$predicted_lifetime_value,
$growth_rate,
$engagement_score,
$visit_consistency,

$patterns['weekday_ratio'],
$patterns['weekend_ratio'],

$patterns['morning_ratio'],
$patterns['afternoon_ratio'],
$patterns['evening_ratio'],
$patterns['night_ratio'],

$prefs['favorite_reference'],
$prefs['preferred_shortcode'],

$identity['first_reference'],
$identity['first_shortcode'],

'Morning',
'Weekday'

]);

}

echo "Customer profiles rebuilt successfully\n";