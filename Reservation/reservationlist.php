<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



// Arama işlemi
$arama = isset($_GET['arama']) ? "%".$baglanti->real_escape_string($_GET['arama'])."%" : '%';

if (isset($_POST['ajax'])) {


    //Iptal butonuna basınca üstünü çizip iptal ediyoruz
    if ($_POST['ajax'] === 'cancel_reservation' && $_POST['id']) {

        $id = intval($_POST['id']);
        $sql = "UPDATE reservations SET cancel_reservation = 1 WHERE id =?";
        $stmt = $baglanti->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        exit;
    }
    //Switch e göre verilerin görüntülenmesi
    if ($_POST['ajax'] === 'get_exist_reservations' && (isset($_POST['durum']) || !isset($_POST['durum'])) && (isset($_POST['sayfa']) || !isset($_POST['sayfa']))) {

        //$durum = ($_POST['durum']);

        $sayfa = isset($_POST['sayfa']) ? (int)$_POST['sayfa'] : 1;

        $durum = (isset($_POST['durum']) && $_POST['durum'] == 1) ? 1 : 0;
        $where_forCount = ($durum == 1) ? 'AND cancel_reservation = ?' : '';


        //Sorgulardaki koşullar için
        $val1 = 0;
        $val2 = 1;

        // Kayıt limiti
        $limit = 10;

        // Mevcut sayfayı belirleme
        //$sayfa = isset($_GET['sayfa']) ? (int)$_GET['sayfa'] : 1;
        $offset = ($sayfa - 1) * $limit;

        

        // Toplam kayıt sayısını bulma
        $sqlCount = "SELECT COUNT(*) as toplam 
        FROM reservations r
        JOIN hotels h ON r.hotel_id = h.id 
        WHERE ((is_group = ? OR is_group = ?) $where_forCount) AND (h.name LIKE ? OR r.id LIKE ?)";
        $stmt = $baglanti->prepare($sqlCount);
        if ($durum == 1) {
            $stmt->bind_param("iiiss", $val1, $val2, $val1, $arama, $arama);
        } else {
            $stmt->bind_param("iiss", $val1, $val2, $arama, $arama);
        }

        $stmt->execute();
        $resultCount = $stmt->get_result();
        $rowCount = $resultCount->fetch_assoc();
        $toplam_kayit = $rowCount['toplam'];
        $toplam_sayfa = ceil($toplam_kayit / $limit);


        $where = ($durum == 1) ? "AND r.cancel_reservation = ?" : "";
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
                                    r.is_group,
                                    r.roomcards_group_id,
                                    r.cancel_reservation
                                FROM reservations r
                                LEFT JOIN hotels h ON r.hotel_id = h.id
                                LEFT JOIN rooms rm ON r.room_id = rm.id
                                LEFT JOIN room_type_name rt ON rm.room_type = rt.id
                                LEFT JOIN agencies a ON r.agency_id = a.agency_id
                                LEFT JOIN currency c ON h.currency = c.id
                                WHERE ((r.is_group = ? OR r.is_group = ?) $where) AND (name LIKE ? OR r.id LIKE ?)
                                LIMIT ? OFFSET ?";

        $stmt = $baglanti->prepare($sql);
        if ($durum == 1) {
            $stmt->bind_param("iiissii", $val1, $val2, $val1, $arama, $arama, $limit, $offset);
        } else {
            $stmt->bind_param("iissii", $val1, $val2, $arama, $arama, $limit, $offset);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        //$result = $baglanti->query($sql);

        // Hata kontrolü
        if (!$result) {
            die("Sorgu başarısız: " . $baglanti->error);
        }

        // Arama çubuğu
        echo "";

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
                        $class = ($row['cancel_reservation'] === 1) ? "cancelled" : "";
                        echo "<tr data-id=" . htmlspecialchars($row["id"]) . " class=" . $class . ">
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
                                <button class='btn btn-danger btn-sm delete-btn'> Sil </button></td>
                                
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
            <a href='#' class='page-link pagination-link' data-sayfa='$i'>$i</a>
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


        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NISROC | Rezervasyon Listesi</title>
    <style>
        .cancelled {
            text-decoration: line-through;
            opacity: 0.6;
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
                        <h1><i class="fa-solid fa-person-walking-luggage"></i> Rezervasyon Listesi</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Rezervasyon Monitörü</a></li>
                            <li class="breadcrumb-item active">Rezervasyon Listesi</li>
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

                                <a href="addreservation.php"><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Yeni rezervasyon</button></a>
                                <a href="cancelreservations.php"><button class="btn btn-danger"><i class="fa-solid fa-minus"></i> İptal Rezervasyonlar</button></a>

                            </div>
                        </div>
                    </div>

                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title"></h3>
                        </div>


                        <div class='card-body'>

                            <!-- Switch -->
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="customSwitch1">
                                <label class="custom-control-label" for="customSwitch1">Sadece mevcut rezervasyonlar</label>
                            </div>

                            <!-- Arama  -->
                            <?php $arama =""; ?>
                            <div class='card-body'>
                                <table align='center' width='100%' class='table table-bordered table-striped'>
                                    <form method='GET' action=''>
                                        <th width='90%'><input type='text' class='form-control' name='arama' value='<?=htmlspecialchars($arama)?>' placeholder='Rezervasyon ara...'></th>
                                        <th><button type='submit' class='btn btn-info btn-sm'><i class='fa-solid fa-magnifying-glass'></i> Arama Yap</button></th>
                                    </form>
                                </table>
                            </div>

                            <div id="reservation_table">

                            </div>

                            <!-- Modal -->
                            <div class="modal fade" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
                                aria-hidden="true">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="exampleModalLabel"><span class="ion-alert-circled"></span> UYARI !</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Kapat">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            KAYIT KALICI OLARAK <u><strong>SİLİNECEK</strong></u> !!! İŞLEME DEVAM ETMEK İSTİYOR MUSUNUZ ?
                                        </div>
                                        <div class="modal-footer">
                                            <!-- Form yerine normal buton -->
                                            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">EVET</button>
                                            <button type="button" class="btn btn-success" data-dismiss="modal">HAYIR</button>
                                        </div>
                                    </div>
                                </div>
                            </div>



                        </div>
                    </div>
                </div>
        </section>
    </div>
    <!-- /.content-wrapper -->

    <script>
        $(document).ready(function() {
            // Sil butonuna tıklama olayı
            let secilenId = null;

            $(document).on('click', '.delete-btn', function() {
                const row = $(this).closest("tr");
                const id = row.data("id");

                secilenId = id; // global olarak sakla
                $('#exampleModal').modal('show');
            });

            $('#confirmDeleteBtn').on('click', function() {
                if (!secilenId) return;

                const row = $('tr[data-id="' + secilenId + '"]');

                if (row.hasClass("cancelled")) return;

                row.addClass("cancelled");

                $.post('', {
                    ajax: 'cancel_reservation',
                    id: secilenId
                }, function(data) {
                    console.log("Silindi: ", data);
                    $('#exampleModal').modal('hide');
                });

                secilenId = null; // işlem sonrası sıfırla
            });

            function tabloyuYenile(sayfa = 1) {
                const durum = $('#customSwitch1').is(':checked') ? 1 : 0;

                $.post('', {
                    ajax: 'get_exist_reservations',
                    durum: durum,
                    sayfa: sayfa
                }, function(data) {
                    $('#reservation_table').html(data);
                });
            }

            $('#customSwitch1').on('change', function() {
                tabloyuYenile(); // sayfa 1'den başlasın
            });

            $(document).on('click', '.pagination-link', function(e) {
                e.preventDefault();
                const sayfa = $(this).data('sayfa');
                tabloyuYenile(sayfa);
            });
            $('#customSwitch1').trigger('change'); // sayfa


        });
    </script>
    <footer class="main-footer">
        <strong>Telif hakkı &copy; 2014-2025 <a href="https://mansurbilisim.com" target="_blank">Mansur Bilişim Ltd. Şti.</a></strong>
        Her hakkı saklıdır.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 1.0.1
        </div>
    </footer>
</body>

</html>