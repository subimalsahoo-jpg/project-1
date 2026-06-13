<?php
include 'auth.php';
requirePermission('vacation_manage');

$message = "";
$edit_data = null;

/* Edit Data Load */
if(isset($_GET['edit'])){
    $edit_id = intval($_GET['edit']);

    $edit_query = mysqli_query($conn, "
        SELECT * FROM holidays 
        WHERE id='$edit_id'
    ");

    if(mysqli_num_rows($edit_query) > 0){
        $edit_data = mysqli_fetch_assoc($edit_query);
    }
}

/* Add Holiday */
if(isset($_POST['save_holiday'])){

    $holiday_date = mysqli_real_escape_string($conn, normalize_input_date($_POST['holiday_date'] ?? ''));
    $holiday_name = mysqli_real_escape_string($conn, $_POST['holiday_name']);

    $check = mysqli_query($conn, "
        SELECT id FROM holidays 
        WHERE holiday_date='$holiday_date'
    ");

    if(mysqli_num_rows($check) > 0){
        $message = "<div class='msg error'>Holiday already exists for this date.</div>";
    } else {
        mysqli_query($conn, "
            INSERT INTO holidays (holiday_date, holiday_name)
            VALUES ('$holiday_date', '$holiday_name')
        ");

        $message = "<div class='msg success'>Holiday Saved Successfully.</div>";
    }
}

/* Update Holiday */
if(isset($_POST['update_holiday'])){

    $id = intval($_POST['id']);
    $holiday_date = mysqli_real_escape_string($conn, normalize_input_date($_POST['holiday_date'] ?? ''));
    $holiday_name = mysqli_real_escape_string($conn, $_POST['holiday_name']);

    $check = mysqli_query($conn, "
        SELECT id FROM holidays 
        WHERE holiday_date='$holiday_date'
        AND id != '$id'
    ");

    if(mysqli_num_rows($check) > 0){
        $message = "<div class='msg error'>Another holiday already exists for this date.</div>";
    } else {
        mysqli_query($conn, "
            UPDATE holidays SET
            holiday_date='$holiday_date',
            holiday_name='$holiday_name'
            WHERE id='$id'
        ");

        header("Location: holidays.php?updated=1");
        exit();
    }
}

if(isset($_GET['updated'])){
    $message = "<div class='msg success'>Holiday Updated Successfully.</div>";
}

/* Delete Holiday */
if(isset($_GET['delete'])){

    $id = intval($_GET['delete']);

    mysqli_query($conn, "
        DELETE FROM holidays 
        WHERE id='$id'
    ");

    header("Location: holidays.php?deleted=1");
    exit();
}

if(isset($_GET['deleted'])){
    $message = "<div class='msg success'>Holiday Deleted Successfully.</div>";
}

$holiday_result = mysqli_query($conn, "
    SELECT * FROM holidays 
    ORDER BY holiday_date DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Holiday Details</title>

<style>
body{
    font-family:Arial;
    background:#f4f6f9;
    margin:0;
    padding:30px;
}

.header{
    background:#1f2933;
    color:white;
    padding:20px;
    border-radius:12px;
    margin-bottom:25px;
}

.header h2{
    margin:0;
    color:#ff8c1a;
}

.btn{
    background:#ff8c1a;
    color:white;
    padding:10px 18px;
    text-decoration:none;
    border:none;
    border-radius:6px;
    cursor:pointer;
    display:inline-block;
}

.dashboard{
    background:#1f2933;
}

.cancel{
    background:#777;
}

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,.08);
    margin-bottom:25px;
}

input{
    padding:10px;
    border:1px solid #ccc;
    border-radius:6px;
    margin-right:10px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    border:1px solid #ddd;
    padding:12px;
    text-align:center;
}

th{
    background:#1f2933;
    color:white;
}

.edit{
    background:#3498db;
    color:white;
    padding:7px 12px;
    border-radius:5px;
    text-decoration:none;
    margin-right:5px;
}

.delete{
    background:red;
    color:white;
    padding:7px 12px;
    border-radius:5px;
    text-decoration:none;
}

.msg{
    padding:12px;
    margin-bottom:15px;
    border-radius:6px;
    font-weight:bold;
}

.success{
    background:#d4edda;
    color:green;
}

.error{
    background:#f8d7da;
    color:red;
}
</style>
</head>

<body>

<div class="header">
    <h2>Holiday Details / Add Holidays</h2>
</div>

<a href="dashboard.php" class="btn dashboard">Dashboard</a>

<br><br>

<?php echo $message; ?>

<div class="box">
    <h3><?php echo $edit_data ? "Edit Holiday" : "Add Holiday"; ?></h3>

    <form method="POST">

        <?php if($edit_data){ ?>
            <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
        <?php } ?>

        <input type="date" name="holiday_date" required
        value="<?php echo $edit_data['holiday_date'] ?? ''; ?>">

        <input type="text" name="holiday_name" placeholder="Holiday Name" required
        value="<?php echo $edit_data['holiday_name'] ?? ''; ?>">

        <?php if($edit_data){ ?>
            <button type="submit" name="update_holiday" class="btn">
                Update Holiday
            </button>

            <a href="holidays.php" class="btn cancel">Cancel</a>
        <?php } else { ?>
            <button type="submit" name="save_holiday" class="btn">
                Save Holiday
            </button>
        <?php } ?>

    </form>
</div>

<div class="box">
    <h3>Holiday List</h3>

    <table>
        <tr>
            <th>SL</th>
            <th>Holiday Date</th>
            <th>Holiday Name</th>
            <th>Action</th>
        </tr>

        <?php
        $sl = 1;
        while($holiday = mysqli_fetch_assoc($holiday_result)){
        ?>

        <tr>
            <td><?php echo $sl++; ?></td>
            <td><?php echo $holiday['holiday_date']; ?></td>
            <td><?php echo $holiday['holiday_name']; ?></td>
            <td>
                <a href="holidays.php?edit=<?php echo $holiday['id']; ?>" class="edit">
                    Edit
                </a>

                <a href="holidays.php?delete=<?php echo $holiday['id']; ?>"
                onclick="return confirm('Delete this holiday?')"
                class="delete">
                    Delete
                </a>
            </td>
        </tr>

        <?php } ?>

    </table>
</div>

</body>
</html>
