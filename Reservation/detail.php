<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Veriyi al
$sql_select = "SELECT adress, phone, gsm, mail, foother, facebook, twitter, instagram, youtube, lk FROM nis_theme_settings ";
$result = $baglanti->query($sql_select);
$row = $result->fetch_assoc();
$adress = isset($row['adress']) ? $row['adress'] : '';
$phone = isset($row['phone']) ? $row['phone'] : '';
$gsm = isset($row['gsm']) ? $row['gsm'] : '';
$mail = isset($row['mail']) ? $row['mail'] : '';
$foother = isset($row['foother']) ? $row['foother'] : '';
$facebook = isset($row['facebook']) ? $row['facebook'] : '';
$twitter = isset($row['twitter']) ? $row['twitter'] : '';
$instagram = isset($row['instagram']) ? $row['instagram'] : '';
$youtube = isset($row['youtube']) ? $row['youtube'] : '';
$lk = isset($row['lk']) ? $row['lk'] : '';



// ID'yi al
$id = isset($_GET['id']) ? $_GET['id'] : null;
$is_group = isset($_GET['is_group']) ? intval($_GET['is_group']) : null;
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;


$where = ($is_group === 1) ? 'reservations.roomcards_group_id = ?' : 'reservations.id = ?';
?>

<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rezervasyon Detayı</title>
</head>

<body>
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1><i class='fa-solid fa-circle-info'></i> Rezervasyon Detayı</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Rezervasyon Monitörü</a></li>
                            <li class="breadcrumb-item"><a href="reservationlist.php">Rezervasyon Listesi</a></li>
                            <li class="breadcrumb-item active">Rezervasyon Detayı</li>
                        </ol>
                    </div>
                </div>
            </div><!-- /.container-fluid -->
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-12">
                    <div class="callout callout-info">

                        <a href="reservationlist.php"><button class="btn btn-danger float-right"><i class="fa fa-times-circle" aria-hidden="true"></i> Çıkış</button></a>

                        <a href="reservationlist.php"><button class="btn btn-danger"><i class="fa-solid fa-angle-left"></i> Geri</button></a>

                        

                    </div>


                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title">
                            </h3>
                        </div>


                        <!-- Main content -->
                        <div class="invoice p-3 mb-3">

                            <?php

                            if ($id) {
                                // SQL sorgusu: nis_reservations ve nis_guests tablolarını birleştirerek verileri al
                                $sql = "
    SELECT 
        reservations.id,
        reservations.hotel_id,
        reservations.room_id,
        reservations.reservation_room_type,
        reservations.reservation_date,
        reservations.check_in,
        reservations.check_out,
        reservations.total_price,
        reservations.currency,
        reservations.adults,
        reservations.children,
        reservations.infant,
        reservations.roomcards_group_id,
        reservations.is_group,
        guests.first_name,
        guests.last_name,
        guests.passport_number,
        guests.child_age,
        guests.phone_number,
        guests.email,
        guests.adress,
        guests.note,
		guests.gender,
        guests.roomcards_group_id,
        guests.is_group,
        guests.reservation_id,
        rooms.room_type,              
        room_type_name.room_type_name,    
        hotels.name AS name       
    FROM 
        reservations
    INNER JOIN 
        guests ON reservations.id = guests.reservation_id
    INNER JOIN 
        rooms ON reservations.room_id = rooms.id
    INNER JOIN 
        room_type_name ON nis_rooms.room_type = room_type_name.id  -- rooms ile roomtype birleştiriliyor
    INNER JOIN 
        hotels ON reservations.hotel_id = hotels.id  
    WHERE 
        $where;
";

                                $stmt = $baglanti->prepare($sql);
                                if ($stmt === false) {
                                    die("Sorgu hazırlama hatası: " . $baglanti->error);
                                }
                                $param = ($is_group === 1) ? $group_id : $id;
                                $stmt->bind_param("i", $param);
                                $stmt->execute();
                                $result = $stmt->get_result();


                                if ($result->num_rows > 0) {
                                    // Rezervasyon bilgilerini listele
                                    echo "<br><strong>Hotel Information:</strong><br>";
                                    echo "<table class='table table-bordered table-striped'><thead><tr>
                <th>Reservation Date</th>
                <th>Hotel ID</th>
                <th>Room ID</th>
                <th>Check In</th>
                <th>Check Out</th>
                <th>Total Price</th>
                <th>Adults</th>
                <th>Children</th>
                </tr></thead><tbody>";


                                    $previousrow = null; //otel bilgilerinde tekrara düşmemek için
                                    $rows = $result->fetch_all(MYSQLI_ASSOC);
                                    $totalsByCurrency = []; // currency => toplam fiyat

                                    //Hotel Info kısmı
                                    foreach ($rows as $row) {
                                        if ($row["roomcards_group_id"] === $group_id && $row["reservation_id"] !== $previousrow) {
                                            echo "<tr>";
                                            echo "<td>" . $row["reservation_date"] . "</td>";
                                            echo "<td>" . $row["name"] . "</td>";
                                            echo "<td>" . $row["room_type_name"] . "</td>";
                                            echo "<td>" . $row["check_in"] . "</td>";
                                            echo "<td>" . $row["check_out"] . "</td>";
                                            echo "<td>" . $row["total_price"] . " " . $row["currency"] . "</td>";
                                            echo "<td>" . $row["adults"] . "</td>";
                                            echo "<td>" . ($row["children"] + $row["infant"]) . "</td>";
                                            echo "</tr>"; // Satır kapama

                                            //TOTAL PRICE hesaplıyoruz
                                            $currency = $row['currency']; // örn: "EUR", "USD", "TRY"
                                            $price = floatval($row['total_price']);

                                            if (!isset($totalsByCurrency[$currency])) {
                                                $totalsByCurrency[$currency] = 0;
                                            }

                                            $totalsByCurrency[$currency] += $price;
                                        }

                                        $previousrow = $row["reservation_id"];
                                    }

                                    echo "</tbody></table>"; // Rezervasyon tablosu kapama


                                    //TOTAL PRICE gösteriyoruz
                                    $priceStrings = [];
                                    foreach ($totalsByCurrency as $currency => $amount) {
                                        $formatted = number_format($amount, 2, ',', '.'); // isteğe göre formatla
                                        $priceStrings[] = $formatted . " " . $currency;
                                    }
                                    echo "<div style='text-align: right; font-weight: bold;'>Toplam Fiyat: " . implode(' + ', $priceStrings) . "</div>";



                                    //Odaların tablolarının gösterimi
                                    $whichRoom = 1;
                                    $previousResId = null;
                                    $previousRoomType = null;
                                    foreach ($rows as $row) {
                                        if ($row["reservation_id"] !== $previousResId) {
                                            // Öncekinden kalma tablo varsa kapat
                                            if ($previousResId !== null) {
                                                echo "</tbody></table>";
                                                echo "<a href='voucher.php?id= $previousResId&is_group=$is_group&group_id=$group_id&send_room_type=$previousRoomType'><button type='button' class='btn btn-primary '>
					  <i class='fa fa-file-text' aria-hidden='true'></i> Voucher </button></a>";
                                            }

                                            // Yeni tablo başlat
                                            echo "<br><strong>$whichRoom. Oda Kartı:</strong><br>";
                                            echo "<table class='table table-bordered table-striped'><thead><tr>
                        <th>Guest Name
                         <th>Passport Number</th>

                         <th>Child Age</th>
                         <th>Phone Number</th>
                         <th>Email</th>
                         <th>Address</th>
                         <th>Note</th>
                         </tr></thead><tbody>";
                                            $whichRoom++;
                                        }

                                        echo "<tr>";
                                        echo "<td>" . $row["gender"] . ". ";
                                        echo "" . $row["first_name"] . "";
                                        echo " " . $row["last_name"] . "";
                                        echo "<td>" . $row["passport_number"] . "</td>";
                                        echo "<td>" . $row["child_age"] . "</td>";
                                        echo "<td>" . $row["phone_number"] . "</td>";
                                        echo "<td>" . $row["email"] . "</td>";
                                        echo "<td>" . $row["adress"] . "</td>";
                                        echo "<td>" . $row["note"] . "</td>";
                                        echo "</tr>"; // Satır kapama


                                        $previousResId = $row["reservation_id"];
                                        $previousRoomType = $row["reservation_room_type"];
                                    }
                                    if ($previousResId !== null) {
                                        
                                        echo "</tbody></table>"; // Misafir tablosu kapama
                                        echo "<a href='voucher.php?id= $previousResId&is_group=$is_group&group_id=$group_id&send_room_type=$previousRoomType'><button type='button' class='btn btn-primary '>
					  <i class='fa fa-file-text' aria-hidden='true'></i> Voucher </button></a>";
                                    }
                                } else {
                                    echo "Kayıt bulunamadı.";
                                }

                                // Kaynakları serbest bırak
                                $stmt->close();
                            } else {
                                echo "Geçerli bir ID girin.";
                            }

                            ?>



                        </div>
                       
                    </div>
        </section>
    </div>
    </div>
    </div>
    <!-- /.content-wrapper -->

    <footer class="main-footer">
        <strong>Telif hakkı &copy; 2014-2025 <a href="https://mansurbilisim.com" target="_blank">Mansur Bilişim Ltd. Şti.</a></strong>
        Her hakkı saklıdır.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 1.0.1
        </div>
    </footer>
</body>

</html>