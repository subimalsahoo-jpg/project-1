<?php
include 'auth.php';
requirePermission('employee_add');

$id = intval($_GET['id']);

$query = "SELECT * FROM employees WHERE id='$id'";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_assoc($result);

if(!$row){
    echo "Employee not found";
    exit();
}

if(isset($_POST['update'])){

    $employee_id = mysqli_real_escape_string($conn, $_POST['employee_id']);
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    $designation = mysqli_real_escape_string($conn, $_POST['designation']);
    $basic_salary = mysqli_real_escape_string($conn, $_POST['basic_salary']);
    $employee_status = mysqli_real_escape_string($conn, $_POST['employee_status']);

    $update = "
    UPDATE employees SET
        employee_id='$employee_id',
        full_name='$full_name',
        department='$department',
        designation='$designation',
        basic_salary='$basic_salary',
        employee_status='$employee_status'
    WHERE id='$id'
    ";

    mysqli_query($conn, $update);

    header("Location: employee_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Employee</title>

<style>
body{
    font-family:Arial;
    background:#f4f4f4;
    padding:30px;
}

form{
    background:white;
    padding:30px;
    width:400px;
    border-radius:10px;
}

input, select{
    width:100%;
    padding:10px;
    margin-bottom:15px;
    box-sizing:border-box;
}

button{
    padding:10px 20px;
    background:#2c3e50;
    color:white;
    border:none;
}

.btn{
    display:inline-block;
    background:#3498db;
    color:white;
    padding:10px 18px;
    text-decoration:none;
    margin-bottom:15px;
}
</style>
</head>

<body>

<a href="employee_list.php" class="btn">Back</a>

<h2>Edit Employee</h2>

<form method="POST">

<label>Employee ID</label>
<input type="text" name="employee_id" value="<?php echo $row['employee_id']; ?>">

<label>Full Name</label>
<input type="text" name="full_name" value="<?php echo $row['full_name']; ?>">

<label>Department</label>
<input type="text" name="department" value="<?php echo $row['department']; ?>">

<label>Designation</label>
<input type="text" name="designation" value="<?php echo $row['designation']; ?>">

<label>Basic Salary</label>
<input type="number" name="basic_salary" value="<?php echo $row['basic_salary']; ?>">

<label>Employee Status</label>
<select name="employee_status">
    <option value="Active" <?php if(($row['employee_status'] ?? 'Active')=='Active') echo 'selected'; ?>>
        Active
    </option>

    <option value="Inactive" <?php if(($row['employee_status'] ?? '')=='Inactive') echo 'selected'; ?>>
        Inactive
    </option>
</select>

<button type="submit" name="update">
Update Employee
</button>

</form>

</body>
</html>
