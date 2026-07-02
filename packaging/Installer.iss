; =====================================================================
;  Payroll System - Inno Setup installer script
;  Build a single setup.exe that bundles the app + PHP + MariaDB.
;
;  HOW TO BUILD (on Windows):
;    1. Install Inno Setup:  https://jrsoftware.org/isdl.php
;    2. Run export_db.bat once (creates db\payroll.sql seed).
;    3. Open this file in Inno Setup Compiler and click Build (F9).
;    4. Output: dist\PayrollSystem-Setup.exe
;
;  ASSUMPTIONS (edit the [Files] source paths if your XAMPP differs):
;    - App code:  C:\xampp\htdocs\payroll
;    - PHP:       C:\xampp\php
;    - MariaDB:   C:\xampp\mysql  (bin, share, scripts)
;
;  The DB connection matches db.php exactly (localhost / root / no
;  password / payroll_system on port 3306), so NO app code changes.
; =====================================================================

#define AppName "Payroll System"
#define AppVersion "1.0.0"
#define Publisher "Euro Trousers"

[Setup]
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#Publisher}
; Install outside Program Files so MariaDB can write to its data dir freely.
DefaultDirName={sd}\PayrollSystem
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=PayrollSystem-Setup
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
WizardStyle=modern

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional icons:"

[Files]
; ---- Application code (payroll) -> {app}\app ----
Source: "C:\xampp\htdocs\payroll\*"; DestDir: "{app}\app"; Excludes: "\.git\*,\packaging\*"; Flags: recursesubdirs createallsubdirs ignoreversion

; ---- PHP runtime -> {app}\php ----
Source: "C:\xampp\php\*"; DestDir: "{app}\php"; Flags: recursesubdirs createallsubdirs ignoreversion

; ---- MariaDB engine -> {app}\mysql (binaries + share + init scripts) ----
Source: "C:\xampp\mysql\bin\*";     DestDir: "{app}\mysql\bin";     Flags: recursesubdirs createallsubdirs ignoreversion
Source: "C:\xampp\mysql\share\*";   DestDir: "{app}\mysql\share";   Flags: recursesubdirs createallsubdirs ignoreversion
Source: "C:\xampp\mysql\scripts\*"; DestDir: "{app}\mysql\scripts"; Flags: recursesubdirs createallsubdirs ignoreversion skipifsourcedoesntexist

; ---- Seed database + launcher scripts (from this packaging folder) ----
Source: "db\payroll.sql";      DestDir: "{app}\db"; Flags: skipifsourcedoesntexist
Source: "config.bat";          DestDir: "{app}"
Source: "start_payroll.bat";   DestDir: "{app}"
Source: "stop_payroll.bat";    DestDir: "{app}"
Source: "firstrun_setup.bat";  DestDir: "{app}"

[Icons]
Name: "{group}\Start Payroll System"; Filename: "{app}\start_payroll.bat"; WorkingDir: "{app}"
Name: "{group}\Stop Payroll System";  Filename: "{app}\stop_payroll.bat";  WorkingDir: "{app}"
Name: "{group}\Uninstall Payroll System"; Filename: "{uninstallexe}"
Name: "{commondesktop}\Payroll System"; Filename: "{app}\start_payroll.bat"; WorkingDir: "{app}"; Tasks: desktopicon

[Run]
; Initialize + import the database once, right after install.
Filename: "{app}\firstrun_setup.bat"; StatusMsg: "Setting up the database (first run)..."; Flags: runhidden waituntilterminated
; Offer to launch the app at the end.
Filename: "{app}\start_payroll.bat"; Description: "Launch Payroll System now"; Flags: postinstall nowait skipifsilent

[UninstallRun]
; Stop servers before uninstalling.
Filename: "{app}\stop_payroll.bat"; Flags: runhidden; RunOnceId: "StopPayroll"

[UninstallDelete]
; Remove the generated MariaDB data directory on uninstall.
Type: filesandordirs; Name: "{app}\mysql\data"
