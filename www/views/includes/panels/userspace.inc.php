<?php ob_start(); ?>

<table class="table-generic">
    <tr>
        <td>LOGIN</td>
        <td><?= $_SESSION['username'] ?></td>
    </tr>
    <tr>
        <td>ROLE</td>
        <td><?= $_SESSION['role'] ?></td>
    </tr>
</table>

<a href="/logout" title="Logout">
    <div class="slide-btn" title="Logout">
        <img src="resources/icons/power.svg" />
        <span>Logout</span>
    </div>
</a>

<h5>PERSONAL INFORMATIONS</h5>

<div>
    <form id="user-edit-info" autocomplete="off">
        <input type="hidden" name="username" value="<?= $_SESSION['username'] ?>" />
        <p>First name:</p>
        <input type="text" class="input-large" name="first-name" value="<?php echo !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : ''; ?>">
        <br><br>
        <p>Last name:</p>
        <input type="text" class="input-large" name="last-name" value="<?php echo !empty($_SESSION['last_name']) ? $_SESSION['last_name'] : ''; ?>">
        <br><br>
        <p>Email:</p>
        <input type="email" class="input-large" name="email" value="<?php echo !empty($_SESSION['email']) ? $_SESSION['email'] : ''; ?>">
        <br><br>
        <button class="btn-small-green">Save</button>
    </form>
</div>

<h5>CHANGE PASSWORD</h5>
            
<div>
    <form id="user-change-password" autocomplete="off">
        <input type="hidden" name="username" value="<?= $_SESSION['username'] ?>" />
        <p>Current password:</p>
        <input type="password" class="input-large" name="actual-password" required />
        <br><br>
        <p>New password:</p>
        <input type="password" class="input-large" name="new-password" required />
        <br><br>
        <p>New password (confirm):</p>
        <input type="password" class="input-large" name="new-password-confirm" required />
        <br><br>
        <button class="btn-small-green">Save</button>
    </form>
</div>

<?php
$content = ob_get_clean();
$paramSlideName = 'userspace';
$paramSlideTitle = 'USERSPACE';

include_once(ROOT . '/views/includes/param-slide.inc.php');