<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Arama işlemi
$arama = isset($_GET['arama']) ? "%".$baglanti->real_escape_string($_GET['arama'])."%" : '%';

if (isset($_POST['ajax']) && $_POST['ajax'] === 'selected_agency' && (isset($_POST['agency_id']))) {

    $agency_id = intval($_POST['agency_id']);
    //echo "acenta no: ". $agency_id;

    $where = ($agency_id === 0) ? '' : 'AND agency_id = ?';

    //Sorgulardaki koşullar için
    $val1 = 0;
    $val2 = 1;



    // Kayıt limiti
    $limit = 10;


    // Mevcut sayfayı belirleme
    $sayfa = isset($_GET['sayfa']) ? (int)$_GET['sayfa'] : 1;
    $offset = ($sayfa - 1) * $limit;

    // Toplam kayıt sayısını bulma
    $sqlCount = "SELECT COUNT(*) as toplam 
    FROM reservations r
    JOIN hotels h ON r.hotel_id = h.id
    WHERE ((is_group = ? OR is_group = ?) AND cancel_reservation = ? $where) AND (h.name LIKE ? OR r.id LIKE ?)";
    $stmt = $baglanti->prepare($sqlCount);
    if ($agency_id === 0) {
        $stmt->bind_param("iiiss", $val1, $val2, $val2, $arama, $arama);
    } else {
        $stmt->bind_param("iiiiss", $val1, $val2, $val2, $agency_id, $arama, $arama);
    }

    $stmt->execute();
    $resultCount = $stmt->get_result();
    $rowCount = $resultCount->fetch_assoc();
    $toplam_kayit = $rowCount['toplam'];
    $toplam_sayfa = ceil($toplam_kayit / $limit);

    $where = ($agency_id === 0) ? '' : 'AND r.agency_id = ?';
    // SQL sorgusu (limit ve offset eklenmiş)
    $sql = "SELECT 
            r.id, 
            h.name AS name, 
            rm.room_type AS room_type, 
            rt.room_type_name AS room_type_name, 
            r.reservation_date, 
            r.check_in, 
            r.check_out, 
            r.total_price,
            r.currency,
            c.currency_symbol AS currency_symbol,
            r.adults, 
            r.children, 
            r.infant,
            a.agency_name,
            a.agency_id, 
            r.is_group,
            r.roomcards_group_id,
            r.cancel_reservation
        FROM nis_reservations r
        LEFT JOIN hotels h ON r.hotel_id = h.id
        LEFT JOIN rooms rm ON r.room_id = rm.id
        LEFT JOIN room_type_name rt ON rm.room_type = rt.id
        LEFT JOIN agencies a ON r.agency_id = a.agency_id
        LEFT JOIN currency c ON h.currency = c.id
        WHERE ((r.is_group = ? OR r.is_group = ?) AND r.cancel_reservation = ? $where) AND (name LIKE ? OR r.id LIKE ?)
        LIMIT ? OFFSET ?";

    $stmt = $baglanti->prepare($sql);
    if ($agency_id === 0) {
        $stmt->bind_param("iiissii", $val1, $val2, $val2, $arama, $arama, $limit, $offset);
    } else {
        $stmt->bind_param("iiiissii", $val1, $val2, $val2, $agency_id, $arama, $arama, $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    //$result = $baglanti->query($sql);

    // Hata kontrolü
    if (!$result) {
        die("Sorgu başarısız: " . $baglanti->error);
    }

?>

    <table class='table table-bordered table-striped'>
        <thead>
            <tr>
                <th>ID</th>
                <th>Acenta Adı</th>
                <th>Rezervasyon Tarihi</th>
                <th>Otel Adı</th>
                <th>Oda Adı</th>
                <th>Giriş Tarihi</th>
                <th>Çıkış Tarihi</th>
                <th>Toplam Ücret</th>
                <th>Döviz Cinsi</th>
                <th>Yetişkin</th>
                <th>Çocuk</th>
                <th></th>

            </tr>
        </thead>
        <tbody>
            <?php
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {

                    echo "<tr data-id=" . htmlspecialchars($row["id"]) . ">
                    <td>" . htmlspecialchars($row["id"]) . "</td>
                    <td>" . htmlspecialchars($row["agency_name"]) . "</td>
                    <td>" . htmlspecialchars($row["reservation_date"]) . "</td>
                    <td>" . htmlspecialchars($row["name"]) . "</td>
                    <td>" . htmlspecialchars($row["room_type_name"]) . "</td>
                    <td>" . htmlspecialchars($row["check_in"]) . "</td>
                    <td>" . htmlspecialchars($row["check_out"]) . "</td>
                    <td>" . htmlspecialchars($row["total_price"]) . " " . htmlspecialchars($row["currency_symbol"]) . "</td>
                    <td>" . htmlspecialchars($row["currency"]) . "</td>
                    <td>" . htmlspecialchars($row["adults"]) . "</td>
                    <td>" . htmlspecialchars(($row["children"] + $row["infant"])) . "</td>
                    <td><a class='btn btn-info btn-sm' href='detail.php?id= " . $row['id'] . "&is_group=" . $row['is_group'] . "&group_id=" . $row['roomcards_group_id'] . "'><i class='fa-solid fa-circle-info'></i> Detay</a>
                    </td>



                    </tr>";
                }
            } else {
                echo "<tr><td colspan='12'>Kayıt bulunamadı</td></tr>";
            }
            ?>
        </tbody>
    </table>
    </div>
<?php
    // Sayfalama düğmeleri
    $max_links = 2;
    $start = max(1, $sayfa - floor($max_links / 2));
    $end = min($toplam_sayfa, $sayfa + floor($max_links / 2));

    if ($end - $start < $max_links - 1) {
        $start = max(1, $end - $max_links + 1);
    }

    echo "<div class='card-body'><nav aria-label='Sayfalama'>
            <ul class='pagination'>
            <!-- Geri düğmesi -->
            <li class='page-item " . ($sayfa <= 1 ? 'disabled' : '') . "'>
            <a class='page-link' href='?sayfa=" . max($sayfa - 1, 1) . "' aria-label='Önceki'>
            <span aria-hidden='true'>&laquo;</span>
            </a>
            </li>";

    // Sayfa numaralarını göster
    for ($i = $start; $i <= $end; $i++) {
        $active = ($i == $sayfa) ? 'active' : '';
        echo "<li class='page-item $active'>
        <a class='page-link' href='?sayfa=$i'>$i</a>
        </li>";
    }

    // İleri düğmesi
    echo "<li class='page-item " . ($sayfa >= $toplam_sayfa ? 'disabled' : '') . "'>
            <a class='page-link' href='?sayfa=" . min($sayfa + 1, $toplam_sayfa) . "' aria-label='Sonraki'>
            <span aria-hidden='true'>&raquo;</span>
            </a>
            </li>
            </ul>
            </nav></div>";

    // Bağlantıyı kapatma
    $baglanti->close();
    exit;
}


?>

<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rezervasyon Listesi</title>

</head>

<>
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1><i class="fa-solid fa-person-walking-luggage"></i> İptal Rezervasyonlar</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Rezervasyon Monitörü</a></li>
                            <li class="breadcrumb-item active">İptal Rezervasyonlar</li>
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
                                <a href="reservationlist.php"><button class="btn btn-danger"><i class="fa-solid fa-angle-left"></i> Geri </button></a>

                            </div>
                        </div>
                    </div>

                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title"></h3>
                        </div>


                        <div class='card-body'>


                            <!-- Acenta Filtreleme  -->
                            <div class="d-flex align-items-center gap-4">
                                <label style="margin-right: 5px;" for="select">Filtrele: </label>
                                <select id="selected_agency" class="form-control w-25 mb-2">
                                    <option value="0" selected>Acenta seçiniz</option>
                                    <?php
                                    $sql = "SELECT agency_id, agency_name FROM nis_agencies";
                                    $result = $baglanti->query($sql);
                                    if ($result->num_rows > 0) {
                                        while ($row = $result->fetch_assoc()) { ?>
                                            <option value="<?= $row['agency_id'] ?>"><?php echo $row['agency_name']; ?></option>
                                        <?php } ?>
                                    <?php } ?>
                                </select>
                            </div>
                            
                            <!-- Arama Alanı -->
                            <?php $arama =""; ?>
                            <div class='card-body'>
                                <table align='center' width='100%' class='table table-bordered table-striped'>
                                    <form method='GET' action=''>
                                        <th width='90%'><input type='text' class='form-control' name='arama' value='<?=htmlspecialchars($arama)?>' placeholder='Rezervasyon ara...'></th>
                                        <th><button type='submit' class='btn btn-info btn-sm'><i class='fa-solid fa-magnifying-glass'></i> Arama Yap</button></th>
                                    </form>
                                </table>
                            </div>

                            

                            <div class="cancelReservationList">

                            </div>






                        </div>
                    </div>
                </div>
        </section>
    </div>
    <!-- /.content-wrapper -->

    <footer class="main-footer">
        <strong>Telif hakkı &copy; 2014-2025 <a href="https://mansurbilisim.com" target="_blank">Mansur Bilişim Ltd. Şti.</a></strong>
        Her hakkı saklıdır.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 1.0.1
        </div>
    </footer>
    <script>
        $(document).ready(function() {

            $('#selected_agency').on('change', function() {
                var agency_id = $(this).val();

                $.post('', {
                    ajax: 'selected_agency',
                    agency_id: agency_id
                }, function(data) {
                    $(".cancelReservationList").html(data);

                });
            });
            $('#selected_agency').trigger('change'); // sayfa 
        });
    </script>
    </body>

</html>