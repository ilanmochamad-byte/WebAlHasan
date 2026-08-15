<?php
session_start();
if($_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// PROSES TAMBAH
if(isset($_POST['tambah'])){
    $nip = mysqli_real_escape_string($koneksi, $_POST['nip']);
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama_guru']);
    $hp = mysqli_real_escape_string($koneksi, $_POST['no_hp']);
    $status = $_POST['status'];
    mysqli_query($koneksi, "INSERT INTO guru (nip, nama_guru, no_hp, status) VALUES ('$nip','$nama','$hp','$status')");
    header("Location: admin_guru.php");
}

// PROSES EDIT
if(isset($_POST['edit'])){
    $id = $_POST['id'];
    $nip = mysqli_real_escape_string($koneksi, $_POST['nip']);
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama_guru']);
    $hp = mysqli_real_escape_string($koneksi, $_POST['no_hp']);
    $status = $_POST['status'];
    mysqli_query($koneksi, "UPDATE guru SET nip='$nip', nama_guru='$nama', no_hp='$hp', status='$status' WHERE id='$id'");
    header("Location: admin_guru.php");
}

// PROSES HAPUS
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    mysqli_query($koneksi, "DELETE FROM guru WHERE id='$id'");
    header("Location: admin_guru.php");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Guru - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Data Guru & Pembimbing</h1>
                <button type="button" class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-plus me-1"></i> Tambah Data
                </button>
            </div>
            <div class="card shadow border-0">
                <div class="card-body">
                    <table id="tabelData" class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>NIP / ID</th>
                                <th>Nama Lengkap</th>
                                <th>No HP</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            $data_guru = [];
                            $data = mysqli_query($koneksi, "SELECT * FROM guru ORDER BY nama_guru ASC");
                            while($d = mysqli_fetch_array($data)){
                                $data_guru[] = $d;
                            ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><?php echo $d['nip']; ?></td>
                                <td class="fw-bold"><?php echo $d['nama_guru']; ?></td>
                                <td><?php echo $d['no_hp']; ?></td>
                                <td>
                                    <?php if($d['status'] == 'Guru') { ?>
                                        <span class="badge bg-primary">Guru</span>
                                    <?php } elseif($d['status'] == 'Pembimbing') { ?>
                                        <span class="badge bg-success">Pembimbing</span>
                                    <?php } else { ?>
                                        <span class="badge bg-dark">Guru & Pembimbing</span>
                                    <?php } ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $d['id']; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                    <a href="admin_guru.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus data ini?')" title="Hapus"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Guru / Pembimbing</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold">NIP (Boleh Kosong)</label>
                        <input type="text" name="nip" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Nama Lengkap (dengan Gelar)</label>
                        <input type="text" name="nama_guru" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">No HP</label>
                        <input type="text" name="no_hp" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Status Kepegawaian</label>
                        <select name="status" class="form-select">
                            <option value="Guru">Guru Pengajar</option>
                            <option value="Pembimbing">Pembimbing Asrama</option>
                            <option value="Keduanya">Guru & Pembimbing</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-success">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach($data_guru as $d): ?>
<div class="modal fade" id="modalEdit<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Data Guru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <div class="mb-3">
                        <label class="fw-bold">NIP</label>
                        <input type="text" name="nip" class="form-control" value="<?php echo $d['nip']; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Nama Lengkap</label>
                        <input type="text" name="nama_guru" class="form-control" value="<?php echo $d['nama_guru']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">No HP</label>
                        <input type="text" name="no_hp" class="form-control" value="<?php echo $d['no_hp']; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Status Kepegawaian</label>
                        <select name="status" class="form-select">
                            <option value="Guru" <?php if($d['status'] == 'Guru') echo 'selected'; ?>>Guru Pengajar</option>
                            <option value="Pembimbing" <?php if($d['status'] == 'Pembimbing') echo 'selected'; ?>>Pembimbing Asrama</option>
                            <option value="Keduanya" <?php if($d['status'] == 'Keduanya') echo 'selected'; ?>>Guru & Pembimbing</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit" class="btn btn-warning">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>$(document).ready(function () { $('#tabelData').DataTable(); });</script>
</body>
</html>