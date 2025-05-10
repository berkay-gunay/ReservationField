<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



if (isset($_POST['ajax'])) {

    

    // UTF-8 karakter seti ayarı (Türkçe karakterlerin düzgün gözükmesi için)
    $baglanti->set_charset("utf8");

    // ⬇ AJAX: Otellere göre odaları getir
    if ($_POST['ajax'] === 'get_rooms' && isset($_POST['hotel_id']) && isset($_POST['adult_count']) && isset($_POST['child_count'])) {
        $hotel_id = intval($_POST['hotel_id']);
        $adult_count = intval($_POST['adult_count']);
        $child_count = intval($_POST['child_count']);
        $query = "SELECT t.id, r.room_type, n.room_type_name, t.min_adult, t.max_adult, t.max_child, t.room_name, h.currency,r.price_policy
              FROM rooms r
              JOIN room_type_name n ON r.room_type = n.id
              JOIN room_type t ON r.room_type = t.room_type_id
              JOIN hotels h ON r.hotel_id = h.id
              WHERE r.hotel_id = ? 
              AND t.min_adult <= ? 
              AND  ? <= t.max_adult 
              AND ? <= t.max_child ";

        $stmt = $baglanti->prepare($query);
        $stmt->bind_param("iiii", $hotel_id, $adult_count, $adult_count, $child_count);
        $stmt->execute();
        $result = $stmt->get_result();

        echo '<option value="">Oda Seçiniz</option>';
        while ($row = $result->fetch_assoc()) {
            echo '<option value="' . $hotel_id . ',' . $row['room_type'] . ',' . $row['room_name'] . ',' . $row['currency'] . ',' . $row['price_policy'] . '">' . htmlspecialchars($row['room_type_name']) . " --- "
                . htmlspecialchars($row['room_name']) . " --- " . htmlspecialchars($row['max_adult']) . " Adult" . " / "
                . htmlspecialchars($row['max_child']) . " Child" . '</option>';
        }
        exit;
    }


    // ⬇ AJAX: Oda fiyatını getir
    if (
        $_POST['ajax'] === 'get_price'
        && isset($_POST['hotel_id'], $_POST['room_id'], $_POST['room_name'], $_POST['checkin'], $_POST['checkout'], $_POST['price_policy'])
    ) {

        $hotel_id = intval($_POST['hotel_id']);
        $room_id = intval($_POST['room_id']);
        $room_name = $_POST['room_name'];
        $checkin = $_POST['checkin'];
        $checkout = $_POST['checkout'];
        $price_policy = intval($_POST['price_policy']);

        // Tarih kontrolü
        if (strtotime($checkin) >= strtotime($checkout)) {
            echo "Hatalı tarih aralığı.";
            exit;
        }
        $this_day = date("Y-m-d");
        if (strtotime($checkin) < strtotime(date("Y-m-d"))) {
            echo "Hatalı tarih girişi";
            exit;
        }

        // Tarih aralığını çıkaralım
        $dates = [];
        $period = new DatePeriod(
            new DateTime($checkin),
            new DateInterval('P1D'),
            new DateTime($checkout)
        );

        foreach ($period as $date) {
            $dates[$date->format("Y-m-d")] = null;
        }

        $price_empty = 0;
        // Fiyatları veritabanından çek
        if ($price_policy === 0) {

            $sql = "SELECT t.rate, y.currency_symbol
                    FROM room_type t
                    JOIN rooms r ON t.hotel_id = r.hotel_id AND t.room_type_id = r.room_type 
                    JOIN contracts c ON t.hotel_id = c.hotel_id AND t.room_type_id = c.room_id AND t.room_name = c.type
                    JOIN currency y ON c.currency = y.id 
                    WHERE t.hotel_id = ? 
                    AND t.room_type_id = ?  
                    AND t.room_name = ? 
                    AND r.start_date <= ? 
                    AND ? <= r.end_date
                    ";
            $stmt = $baglanti->prepare($sql);
            $stmt->bind_param("iisss", $hotel_id, $room_id, $room_name, $checkin, $checkout);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            $rate = $row['rate'];
            $currency_symbol = $row['currency_symbol'];
            $currentDate = new DateTime($checkin);
            $endDate = new DateTime($checkout);

            if (is_null($rate) || empty($rate)) {
                $price_empty = 1;
            } else {
                while ($currentDate < $endDate) {

                    $dates[$currentDate->format('Y-m-d')] = floatval($rate);  // Her güne aynı fiyatı ata
                    $currentDate->modify('+1 day');
                }
            }
        } else {
            $query = "SELECT a.date, a.price, y.currency_symbol
                FROM amount a
                JOIN contracts c ON a.hotel_id = c.hotel_id AND a.room_type = c.room_id AND a.type = c.type
                JOIN currency y ON c.currency = y.id 
                WHERE a.hotel_id = ? 
                AND a.room_type = ?  
                AND a.type = ? 
                AND a.date >= ? 
                AND a.date < ?";

            $stmt = $baglanti->prepare($query);
            $stmt->bind_param("iisss", $hotel_id, $room_id, $room_name, $checkin, $checkout);
            $stmt->execute();
            $result = $stmt->get_result();

            // Gelen fiyatları yerleştir
            while ($row = $result->fetch_assoc()) {
                //$dates[$row['date']] = $row['price'];
                // İlk satırdaki currency_symbol'u al
                $currency_symbol = $row['currency_symbol'];

                // İlk satırı da dates dizisine ekle
                $dates[$row['date']] = floatval($row['price']);

                // Diğer satırlar için döngü
                while ($row = $result->fetch_assoc()) {
                    $dates[$row['date']] = floatval($row['price']);
                }
            }
        }


        $flag = 0;
        // eğer rate te fiyat yoksa buraya girmiyor.
        if ($price_empty === 0) {

            // Şimdi bloklara ayıralım
            $blocks = [];
            $currentPrice = null;
            $blockStart = null;
            $musaitlikYok = false;
            $totalPrice = 0.0;

            foreach ($dates as $date => $price) {
                if (is_null($price) || empty($price)) {
                    // Müsaitlik yoksa blok kapat
                    if ($blockStart !== null) {
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => $currentPrice];
                        $blockStart = null;
                        $currentPrice = null;
                    }
                    $musaitlikYok = true;
                    break; // Devamını kontrol etmiyoruz
                }


                if ($price !== $currentPrice) {
                    // Fiyat değiştiyse blok kapat ve yenisini başlat
                    if ($blockStart !== null) {
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => $currentPrice];
                    }
                    $blockStart = $date;
                    $currentPrice = $price;
                }

                //$totalPrice += $currentPrice;
            }

            // Son bloğu kapat
            if ($blockStart !== null) {
                $lastDate = array_key_last($dates);
                $blocks[] = ['start' => $blockStart, 'end' => $lastDate, 'price' => $currentPrice];
            }

            $prices = [];
            $flag = 0;
            if (empty($blocks)) {
                echo "<div style='color:red; font-weight:bold;'>Seçilen tarihlerde müsaitlik yok</div>";
                $flag = 1;
            } else {
                foreach ($blocks as $b) {
                    $start = date('d.m.Y', strtotime($b['start']));
                    $end = date('d.m.Y', strtotime($b['end'] . '+1 day'));
                    $nights = (strtotime($b['end']) - strtotime($b['start'])) / (60 * 60 * 24) + 1;
                    echo "<div style='margin-bottom:5px;'>➤ <strong>$start - $end</strong> arası ( $nights gece ) gecelik fiyat: <strong>{$b['price']} $currency_symbol</strong></div>";

                    $totalPrice += floatval($nights) * floatval($b["price"]);
                }
                if ($musaitlikYok) {
                    echo "<div style='color:red; font-weight:bold;'>Sonraki tarihlerde müsaitlik yok</div>";
                    $flag = 1;
                }
            }
            //Her oda kartının totalprice ını bir diziye atıyoruz.
            $prices = $totalPrice;
            echo "<input type='hidden' name='price[]' value='$prices'>";

            //echo "<input type='hidden' id='availability_flag_$room_id' class='availability-flag' value='$flag'>";
        } else {
            echo "<div style='color:red; font-weight:bold;'>Fiyat bulunamadı</div>";
            $flag = 1;
        }

        echo "<input type='hidden' id='availability_flag_$room_id' class='availability-flag' value='$flag'>";

        exit;
    }
}



?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NISROC | Yeni Rezervasyon</title>

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        .select2-container--default .select2-selection--single {
            height: 38px !important;
            /* Bootstrap form-control yüksekliği */
            padding: 6px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .select2-selection__rendered {
            line-height: 24px !important;
            /* İçerideki yazı yüksekliği */
        }

        .select2-selection__arrow {
            height: 38px !important;
        }
    </style>

</head>

<body>
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1><i class="fa-solid fa-bell-concierge"></i> Yeni Rezervasyon</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Rezervasyon Monitörü</a></li>
                            <li class="breadcrumb-item active"><a href="reservationlist.php">Rezervasyon Listesi</a>
                            </li>
                            <li class="breadcrumb-item active">Yeni Rezervasyon</li>
                        </ol>
                    </div>
                </div>
            </div><!-- /.container-fluid -->
        </section>

        <section class="content">
            <!-- ./row -->
            <div class="row">
                <div class="col-md-12">

                    <div class="callout callout-info">
                        <div class="row no-print">
                            <div class="col-12">

                                <a href="reservationlist.php"><button class="btn btn-danger"><i
                                            class="fa-solid fa-angle-left"></i> Geri</button></a>

                            </div>
                        </div>
                    </div>

                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title"></h3>
                        </div>


                        <div class='card-body'>


                            <script>
                                function addRoomInputs() {
                                    const roomCount = parseInt(document.getElementById('roomCount').value);
                                    const roomsContainer = document.getElementById('roomsContainer');
                                    roomsContainer.innerHTML = '';

                                    for (let i = 1; i <= roomCount; i++) {
                                        const roomDiv = document.createElement('div');
                                        roomDiv.classList.add('col-md-6', 'mb-4');

                                        roomDiv.innerHTML = `
                <div class="border p-3 rounded bg-light">
                    <h5 class="text-info mb-3">${i}. Oda</h5>
                    <div class="form-group">
                        <label for="adultCount_${i}">Yetişkin Sayısı</label>
                        <input type="number" class="form-control" id="adultCount_${i}" name="adultCount_${i}" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="childCount_${i}">Çocuk Sayısı</label>
                        <input type="number" class="form-control" id="childCount_${i}" name="childCount_${i}" min="0" required>
                    </div>
                </div>
            `;

                                        roomsContainer.appendChild(roomDiv);
                                    }
                                }
                            </script>

                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_cards">
                                <table id="adultsTable" class="table table-bordered table-striped">
                                    <div class="form-group row">
                                        <label for="roomCount" class="col-sm-2 col-form-label font-weight-bold">Oda Sayısı:</label>
                                        <div class="col-sm-4">
                                            <input type="number" class="form-control" id="roomCount" name="roomCount" min="1" required onchange="addRoomInputs()">
                                        </div>
                                    </div>


                            </form>
                            </table>

                            <hr>
                            <table>
                                <div id="roomsContainer"></div>


                                <button type="submit" class="btn btn-primary">Oda Kartı Aç</button>
                            </table>

                            <div class="container mt-5">
                                <?php
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
                                    $roomCount = intval($_POST['roomCount']); ?>
                                    <form id="contact" method="POST" action="">
                                        <?php for ($roomId = 1; $roomId <= $roomCount; $roomId++) {
                                            $adultCount = intval($_POST["adultCount_$roomId"]);
                                            $childCount = intval($_POST["childCount_$roomId"]);

                                            $hotel_id = isset($_POST["hotel_id_$roomId"]) ? intval($_POST["hotel_id_$roomId"]) : 0;

                                            $agency_sql = "SELECT agency_id, agency_name FROM nis_agencies";
                                            $agency_result = $baglanti->query($agency_sql);

                                            $hotel_sql = "SELECT id, name FROM nis_hotels";
                                            $hotel_result = $baglanti->query($hotel_sql);

                                            $rooms_sql = "SELECT r.room_type, n.id, n.room_type_name 
                                                            FROM rooms r
                                                            JOIN room_type_name n ON r.room_type = n.id
                                                            WHERE r.hotel_id = ?";

                                            $stmt = $baglanti->prepare($rooms_sql); // Sorguyu hazırlayın
                                            if (!$stmt) {
                                                die("Sorgu hazırlama hatası: " . $baglanti->error); // Hata kontrolü
                                            }

                                            $stmt->bind_param("i", $hotel_id); // Parametreyi bağlayın
                                            if (!$stmt->execute()) {
                                                die("Sorgu çalıştırma hatası: " . $stmt->error); // Hata kontrolü
                                            }
                                            $result = $stmt->get_result(); // Sonuçları alın

                                        ?>
                                            <input type="hidden" name="room_count" value="<?= $roomCount ?>">
                                            <input type="hidden" name="actionR" value="save_reservation">
                                            <input type='hidden' id='adult_count_<?= $roomId ?>' name="<?= 'adult_count_' . $roomId ?>"
                                                value='<?= $adultCount ?>'>
                                            <input type='hidden' id='child_count_<?= $roomId ?>' name="<?= 'child_count_' . $roomId ?>"
                                                value='<?= $childCount ?>'>
                                            <div class="card mb-4 oda-karti" data-room-id="<?= $roomId ?>">
                                                <div class="card-header">
                                                    <h4><?php echo $roomId; ?>. Oda Kartı</h4>
                                                </div>
                                                <div class="card-body">
                                                    <table align="center" width="100%"
                                                        class="table table-bordered table-striped">

                                                        <tr>
                                                            <th style="width: 10%;">Giriş Tarihi:</th>
                                                            <td><input type="date" name="<?= 'checkin_' . $roomId ?>" class="form-control checkin"
                                                                    data-room-id="<?= $roomId ?>"></td>
                                                            <th style="width: 20%;">Çıkış Tarihi:</th>
                                                            <td><input type="date" name="<?= 'checkout_' . $roomId ?>" class="form-control checkout"
                                                                    data-room-id="<?= $roomId ?>"></td>
                                                        </tr>

                                                        <tr>
                                                            <th>Acenta:</th>
                                                            <td>
                                                                <select name="<?= 'agency_id_' . $roomId ?>"
                                                                    class="form-control">
                                                                    <?php while ($row = $agency_result->fetch_assoc()) { ?>
                                                                        <option value="<?= $row['agency_id'] ?>">
                                                                            <?= htmlspecialchars($row['agency_name']) ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </td>
                                                            <th>Otel:</th>
                                                            <td style="width: 40%;">
                                                                <select name="hotel_id_<?= $roomId ?>"
                                                                    class="form-control hotel-select select2"
                                                                    data-room-id="<?= $roomId ?>">
                                                                    <option value="">Otel Seçiniz</option>
                                                                    <?php
                                                                    // Otelleri tekrar al (çünkü yukarıdaki while ilerletti)
                                                                    $hotel_result = $baglanti->query($hotel_sql);
                                                                    while ($row = $hotel_result->fetch_assoc()) { ?>
                                                                        <option value="<?= $row['id'] ?>">
                                                                            <?= htmlspecialchars($row['name']) ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>Oda Tipi:</th>
                                                            <td colspan="3">
                                                                <select name="room_type_<?= $roomId ?>"
                                                                    class="form-control room-select"
                                                                    data-room-id="<?= $roomId ?>">
                                                                    <option value="">Önce otel seçin</option>
                                                                </select>
                                                                <div id="price-info-<?= $roomId ?>" style="margin-top:10px;">
                                                                </div>
                                                            </td>
                                                        </tr>


                                                        <?php for ($i = 1; $i <= $adultCount; $i++) { ?>
                                                            <tr>
                                                                <td><select class="form-control"
                                                                        name="<?= 'room_' . $roomId . '_adult_' . $i . '_gender' ?>">
                                                                        <option value="Mr">Mr</option>
                                                                        <option value="Mrs">Mrs</option>
                                                                    </select></td>
                                                                <td><input type="text" class="form-control"
                                                                        name="<?= 'room_' . $roomId  . '_adult_' . $i . '_first_name' ?>"
                                                                        placeholder="First Name" required></td>
                                                                <td><input type="text" class="form-control"
                                                                        name="<?= 'room_' . $roomId . '_adult_' . $i . '_last_name' ?>"
                                                                        placeholder="Last Name" required></td>
                                                                <td><input type="text" class="form-control"
                                                                        name="<?= 'room_' . $roomId . '_adult_' . $i . '_passport' ?>"
                                                                        placeholder="Passport"></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </table>

                                                    <!-- çocuklar için input alanları -->
                                                    <?php if ($childCount > 0) { ?>
                                                        <table class="table table-bordered table-striped">
                                                            <?php for ($j = 1; $j <= $childCount; $j++) { ?>
                                                                <tr>
                                                                    <td><select class="form-control"
                                                                            name="<?= 'room_' . $roomId . '_child_' . $j . '_gender' ?>"
                                                                            readonly>
                                                                            <option value="Child">Child</option>
                                                                        </select></td>
                                                                    <td><input type="text" class="form-control"
                                                                            name="<?= 'room_' . $roomId . '_child_' . $j . '_first_name' ?>"
                                                                            placeholder="First Name" required></td>
                                                                    <td><input type="text" class="form-control"
                                                                            name="<?= 'room_' . $roomId . '_child_' . $j . '_last_name' ?>"
                                                                            placeholder="Last Name" required></td>
                                                                    <td><input type="number" class="form-control"
                                                                            name="<?= 'room_' . $roomId . '_child_' . $j . '_age' ?>"
                                                                            placeholder="Age" min="0" max="16" required></td>
                                                                    <td><input type="text" class="form-control"
                                                                            name="<?= 'room_' . $roomId . '_child_' . $j . '_passport' ?>"
                                                                            placeholder="Child Passport"></td>
                                                                </tr>
                                                            <?php } ?>

                                                        </table>
                                                    <?php } ?>
                                                    <table class="table table-bordered table-striped">
                                                        <tr>
                                                            <th>E-mail:</th>
                                                            <td><input type="email" class="form-control" name="<?= 'email_' . $roomId ?>" placeholder="Mail adresi"></td>
                                                            <th>Cep Telefonu :</th>
                                                            <td><input type="text" class="form-control" name="<?= 'phoneNumber_' . $roomId ?>" placeholder="Telefon no giriniz"></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Adres:</th>
                                                            <td colspan="3">
                                                                <textarea class="form-control" name="<?= 'address_' . $roomId ?>" placeholder="Address"></textarea>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>Not:</th>
                                                            <td colspan="3"><textarea name="<?= 'note_' . $roomId ?>" class="form-control" placeholder="Not giriniz"></textarea></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <button type="submit" id="submitButton" class="btn btn-success">Kaydet</button>
                                        <input type="checkbox" class="" name="checkbox" value="1" <?php if ($roomCount === 1) {
                                                                                                        echo "disabled";
                                                                                                    } ?>>
                                        <label for="checkbox"> Hepsi tek kayıt olsun</label>
                                    </form>
                                <?php } ?>

                                <?php
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actionR'])) {

                                    $roomCount = intval($_POST['room_count']);

                                    $is_group = isset($_POST["checkbox"]) ? 1 : 0;


                                    // 1. Önce reservation_groups tablosuna yeni kayıt ekle
                                    $baglanti->query("INSERT INTO reservation_groups () VALUES ()");
                                    // 2. Insert edilen grup ID'yi al
                                    $reservation_group_id = $baglanti->insert_id;


                                    for ($roomId = 1; $roomId <= $roomCount; $roomId++) {

                                        // ODA BİLGİLERİ
                                        $checkin = $_POST["checkin_$roomId"];
                                        $checkout = $_POST["checkout_$roomId"];
                                        $hotel_id = $_POST["hotel_id_$roomId"];
                                        $room_type_id = $_POST["room_type_$roomId"];
                                        $explode =  explode(",", $room_type_id);
                                        $room_name_id = $explode[1];
                                        $room_name = $explode[2];
                                        $currency = $explode[3];
                                        $agency_id = $_POST["agency_id_$roomId"];
                                        $adult_count = intval($_POST["adult_count_$roomId"]);
                                        $child_count = $_POST["child_count_$roomId"];
                                        $prices = $_POST["price"];
                                        $email = $_POST["email_$roomId"];
                                        $phone_number = $_POST["phoneNumber_$roomId"];
                                        $address = $_POST["address_$roomId"];
                                        $note = $_POST["note_$roomId"];

                                        //Child ve Infant sayısını alıyoruz
                                        $num_of_child = 0;
                                        $num_of_infant = 0;

                                        for ($j = 1; $j <= $child_count; $j++) {
                                            $age = intval($_POST["room_{$roomId}_child_{$j}_age"]);
                                            if ($age > 1) {
                                                $num_of_child++;
                                            } else {
                                                $num_of_infant++;
                                            }
                                        }

                                        //Odanın kayıt ID sini alıyoruz
                                        $stmt = $baglanti->prepare("SELECT id FROM rooms WHERE hotel_id=? AND room_type=? LIMIT 1");
                                        $stmt->bind_param("ii", $hotel_id, $room_name_id);
                                        $stmt->execute();
                                        $stmt->bind_result($room_id);
                                        $stmt->fetch();
                                        $stmt->close();

                                        //Para birimini alıyoruz
                                        $stmt = $baglanti->prepare("SELECT currency_name FROM currency WHERE id=? LIMIT 1");
                                        $stmt->bind_param("i", $currency);
                                        $stmt->execute();
                                        $stmt->bind_result($currency_name);
                                        $stmt->fetch();
                                        $stmt->close();

                                        // ODA KAYDI
                                        $stmt = $baglanti->prepare("INSERT INTO reservations (hotel_id,  room_id, reservation_room_type, check_in, check_out, total_price , currency, adults, children, infant, agency_id, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                        $stmt->bind_param("iisssdsiiiiii", $hotel_id, $room_id, $room_name,  $checkin, $checkout, $prices[($roomId - 1)], $currency_name, $adult_count, $num_of_child, $num_of_infant, $agency_id, $reservation_group_id, $is_group);
                                        $stmt->execute();
                                        $reservation_id = $stmt->insert_id; // eklenen oda kaydının ID'si

                                        if ($is_group === 1) {
                                            $is_group = null;
                                        }

                                        // YETİŞKİN BİLGİLERİ
                                        //$adultCount = intval($_POST["adult_count_$roomId"]);
                                        for ($i = 1; $i <= $adult_count; $i++) {
                                            $gender = $_POST["room_{$roomId}_adult_{$i}_gender"];
                                            $first_name = $_POST["room_{$roomId}_adult_{$i}_first_name"];
                                            $last_name = $_POST["room_{$roomId}_adult_{$i}_last_name"];
                                            $passport = $_POST["room_{$roomId}_adult_{$i}_passport"];

                                            $stmt2 = $baglanti->prepare("INSERT INTO guests (reservation_id, gender, first_name, last_name, passport_number, phone_number, email, adress, note, agency_id, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                            $stmt2->bind_param("isssssssssii", $reservation_id, $gender, $first_name, $last_name, $passport, $phone_number, $email, $address, $note, $agency_id, $reservation_group_id, $is_group);
                                            $stmt2->execute();
                                        }

                                        // ÇOCUK BİLGİLERİ
                                        //$childCount = intval($_POST["childCount_$roomId"]);
                                        for ($j = 1; $j <= $child_count; $j++) {
                                            $gender = $_POST["room_{$roomId}_child_{$j}_gender"];
                                            $first_name = $_POST["room_{$roomId}_child_{$j}_first_name"];
                                            $last_name = $_POST["room_{$roomId}_child_{$j}_last_name"];
                                            $age = intval($_POST["room_{$roomId}_child_{$j}_age"]);
                                            $child_passport = $_POST["room_{$roomId}_child_{$j}_passport"];

                                            $stmt3 = $baglanti->prepare("INSERT INTO guests (reservation_id, gender, first_name, last_name, passport_number, child_age, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                            $stmt3->bind_param("issssiii", $reservation_id, $gender, $first_name, $last_name, $child_passport, $age, $reservation_group_id, $is_group);
                                            $stmt3->execute();
                                        }
                                    }

                                    echo "<div class='alert alert-success'>Tüm rezervasyonlar başarıyla kaydedildi.</div>";
                                }
                                ?>



                            </div>

                        </div><!-- /.card-body-->


                        
                    </div>
                </div>
            </div>
        </section>
    </div>
    <!-- /.content-wrapper -->

    <footer class="main-footer">
        <strong>Telif hakkı &copy; 2014-2025 <a href="https://mansurbilisim.com" target="_blank">Mansur Bilişim Ltd.
                Şti.</a></strong>
        Her hakkı saklıdır.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 1.0.1
        </div>
    </footer>
    <!-- jQuery & AJAX -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).on('change', '.hotel-select', function() {
            var hotelID = $(this).val();
            var roomId = $(this).data('room-id');
            var adult_count = $('#adult_count_' + roomId).val();
            var child_count = $('#child_count_' + roomId).val();

            // Bu satır: aynı oda kartındaki room-select'i bulur
            var roomSelect = $('.room-select[data-room-id="' + roomId + '"]');

            if (hotelID !== '') {
                $.post('', {
                    ajax: 'get_rooms',
                    hotel_id: hotelID,
                    adult_count: adult_count,
                    child_count: child_count
                }, function(data) {
                    roomSelect.html(data);
                });
            } else {
                roomSelect.html('<option value="">Oda Seçiniz</option>');
            }
        });


        $(document).on('change', '.room-select', function() {
            var roomId = $(this).data('room-id');
            var hotelID = $('.hotel-select[data-room-id="' + roomId + '"]').val();
            var roomTypeID = $(this).val().split(',')[1]; // senin option value'su: hotelID,roomTypeID,room_name
            var roomName = $(this).val().split(',')[2]; // senin option value'su: hotelID,roomTypeID,room_name
            var pricePolicy = $(this).val().split(',')[4]; // senin option value'su: hotelID,roomTypeID,room_name
            var checkin = $('.checkin[data-room-id="' + roomId + '"]').val();
            var checkout = $('.checkout[data-room-id="' + roomId + '"]').val();

            if (hotelID && roomTypeID && roomName && checkin && checkout) {
                $.post('', {
                    ajax: 'get_price',
                    hotel_id: hotelID,
                    room_id: roomTypeID,
                    room_name: roomName,
                    checkin: checkin,
                    checkout: checkout,
                    price_policy: pricePolicy
                }, function(data) {
                    // fiyat yazdırma yeri
                    $('#price-info-' + roomId).html(data);

                    // Tüm availability_flag inputlarını kontrol et
                    let flags = document.querySelectorAll('.availability-flag');
                    let disableSubmit = false;

                    flags.forEach(function(input) {
                        if (parseInt(input.value) === 1) {
                            disableSubmit = true;
                        }
                    });

                    // Kaydet butonunu bul ve duruma göre devre dışı bırak veya aç
                    document.getElementById("submitButton").disabled = disableSubmit;

                });
            } else {
                alert('Lütfen önce otel, tarih ve oda tipi seçin.');
            }
        });
        //Yapılan aramının üstüne yeni arama yapmak isterse diğer bilgileri sıfırlıyoruz
        $(document).on('change', '.checkin, .checkout', function() {
            var roomId = $(this).data('room-id');

            // Otel ve oda selectlerini sıfırla
            $('.hotel-select[data-room-id="' + roomId + '"]').val('').trigger('change');
            $('.room-select[data-room-id="' + roomId + '"]').html('<option value="">Önce otel seçin</option>');

            // Fiyat bilgisini de temizle
            $('#price-info-' + roomId).html('');
        });

        $(document).ready(function() {
            $('.select2').select2({
                width: '100%', // Genişliği form-control ile uyumlu hale getirir
                placeholder: "Otel seçiniz",
                allowClear: true
            });
        });
    </script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

</body>

</html>