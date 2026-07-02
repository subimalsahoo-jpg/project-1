# Payroll System — `.exe` Installer বানানোর গাইড

এই ফোল্ডারের ফাইলগুলো দিয়ে পুরো Payroll সফটওয়্যারকে একটা **`setup.exe`**-তে
প্যাকেজ করা যায়, যা অন্য কম্পিউটারে ডাবল-ক্লিক করে ইনস্টল করা যাবে।
অ্যাপের PHP কোডে **কোনো পরিবর্তন করা হয়নি** — bundle করা MariaDB ঠিক
`db.php`-এর সেটিংসেই চলে (localhost / root / পাসওয়ার্ড নেই / `payroll_system` / port 3306)।

---

## এটা যেভাবে কাজ করে

`setup.exe` টার্গেট মেশিনে বসায়:

```
C:\PayrollSystem\
   app\                 <- আপনার payroll PHP কোড
   php\                 <- PHP runtime (php -S web server)
   mysql\               <- portable MariaDB (bin, share)
      data\             <- প্রথম রানে অটো-তৈরি হয়
   db\payroll.sql       <- seed ডেটা (আপনার export)
   config.bat           <- পোর্ট / DB সেটিংস
   start_payroll.bat    <- সব চালু করে + browser খোলে
   stop_payroll.bat     <- সব বন্ধ করে
   firstrun_setup.bat   <- প্রথম রানে DB তৈরি + import
```

- **DB:** bundle করা MariaDB চলে `127.0.0.1:3306`-এ (root, পাসওয়ার্ড নেই)।
- **Web:** PHP built-in server চলে `http://localhost:8080`-এ।
- Shortcut-এ ক্লিক করলে `start_payroll.bat` দুটো সার্ভার চালু করে browser-এ
  অ্যাপ খোলে।

---

## ধাপ ১ — আপনার মেশিনে (SOURCE) যা লাগবে

1. **XAMPP** ইনস্টল থাকতে হবে (`C:\xampp` — এখানেই PHP + MariaDB আছে)।
2. **Inno Setup** নামান ও ইনস্টল করুন → https://jrsoftware.org/isdl.php (ফ্রি)।
3. এই `packaging` ফোল্ডারটা আপনার Windows মেশিনে নিন
   (রিপোর সাথে `C:\xampp\htdocs\payroll\packaging` হিসেবেই থাকবে)।

> XAMPP অন্য জায়গায় থাকলে `Installer.iss` আর `export_db.bat`-এর উপরের দিকে
> পাথগুলো বদলে দিন।

---

## ধাপ ২ — বর্তমান ডেটাবেস export করুন (seed)

`packaging` ফোল্ডারে গিয়ে ডাবল-ক্লিক করুন:

```
export_db.bat
```

- এটা শুধু **পড়ে** (mysqldump) — আপনার live ডেটাবেসে কিছু বদলায় না।
- তৈরি হবে `packaging\db\payroll.sql` (schema + বর্তমান সব ডেটা)।

> **খালি অ্যাপ পাঠাতে চাইলে** (কোনো employee/ডেটা ছাড়া) এই ধাপ বাদ দিন —
> তখন installer একটা ফাঁকা `payroll_system` ডেটাবেস বানাবে।

---

## ধাপ ৩ — `setup.exe` বানান

1. `Installer.iss` ফাইলটা **Inno Setup Compiler**-এ খুলুন।
2. **Build** চাপুন (অথবা F9 / মেনু: Build ▸ Compile)।
3. তৈরি হবে: `packaging\dist\PayrollSystem-Setup.exe`

এই একটা ফাইলই অন্য কোম্পানিতে নিয়ে যাবেন।

---

## ধাপ ৪ — টার্গেট কম্পিউটারে ইনস্টল

1. `PayrollSystem-Setup.exe` ডাবল-ক্লিক করুন (Run as administrator)।
2. ইনস্টল শেষে সে নিজেই ডেটাবেস তৈরি + import করবে (`firstrun_setup.bat`)।
3. Desktop-এর **“Payroll System”** shortcut-এ ক্লিক → browser-এ লগইন পেজ খুলবে।
4. বন্ধ করতে Start Menu থেকে **Stop Payroll System**।

---

## গুরুত্বপূর্ণ নোট

- **টার্গেট মেশিনে যেন আগে থেকে MySQL/XAMPP পোর্ট 3306-এ চালু না থাকে।**
  থাকলে conflict হবে — তখন `config.bat`-এ `DB_PORT` (যেমন 3307) বদলাতে হবে,
  কিন্তু তাহলে `db.php`-এও পোর্ট দিতে হবে (তখন সামান্য কোড পরিবর্তন লাগবে)।
  নতুন/ফাঁকা মেশিনে সাধারণত 3306 ফাঁকা থাকে, তাই সমস্যা হয় না।
- ওয়েব পোর্ট 8080 ব্যস্ত থাকলে `config.bat`-এ `APP_PORT` বদলে দিন।
- ইনস্টল হয় `C:\PayrollSystem`-এ (Program Files-এ নয়) — যাতে MariaDB তার
  `data` ফোল্ডারে অবাধে লিখতে পারে।
- `db\payroll.sql`-এ আসল employee ডেটা থাকতে পারে — এটা **git-এ কমিট হয় না**
  (`.gitignore`-এ বাদ দেওয়া আছে)। শুধু installer-এ যায়।

---

## Licensing

অন্য কোম্পানিতে distribute/install করার আগে নিশ্চিত করুন যে সফটওয়্যার ও তার
সব লাইব্রেরি বিতরণের অধিকার আপনার আছে।
