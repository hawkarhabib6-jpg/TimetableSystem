; ============================================================
; دامەزرێنەری یەکگرتوو: لەسەر هەر ویندۆزێک خۆی وەشانی گونجاو هەڵدەبژێرێت
;  - ویندۆز 10/11 ی 64-bit  → وەشانی مۆدێرن (PHP Desktop + Chrome، پەنجەرەی خۆی)
;  - ویندۆز 7/8/8.1 یان 32-bit → وەشانی «هەموو ویندۆزەکان» (PHP 32-bit + Edge/Chrome)
; ============================================================
#define AppDir GetEnv('PHPDESKTOP_DIR')
#define UniDir GetEnv('UNIVERSAL_DIR')
#ifndef AppVersion
  #define AppVersion "3.0.0"
#endif

[Setup]
AppId={{6F1B7C2E-4A1D-4E8B-9C35-2D7A1B0E5F42}
AppName=School Timetable System
AppVersion={#AppVersion}
AppPublisher=TimetableSystem
; بۆ هەر بەکارهێنەرێک جیا دادەمەزرێت → پێویستی بە مافی Administrator نییە
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
; PHP Desktop ناتوانێت PHP بکاتەوە ئەگەر ڕێڕەوەکە بۆشایی (space)ی تێدابێت،
; بۆ نموونە C:\Users\Ahmad Ali\... ، بۆیە ڕێڕەوێکی بێ بۆشایی بەکاردەهێنین
DefaultDirName={sd}\TimetableSystem
DefaultGroupName=TimetableSystem
DisableProgramGroupPage=yes
OutputBaseFilename=TimetableSystem-Setup
OutputDir=.
Compression=lzma2/max
SolidCompression=yes
; لەسەر 64-bit بە 64-bit دادەمەزرێت، لەسەر 32-bit بە 32-bit
ArchitecturesInstallIn64BitMode=x64
MinVersion=6.1sp1

[Files]
Source: "{#AppDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion; Check: IsModern
Source: "{#UniDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion; Check: not IsModern

[Dirs]
Name: "{app}\www\tmp"; Permissions: users-modify

[Icons]
Name: "{group}\School Timetable"; Filename: "{app}\TimetableSystem.exe"; Check: IsModern
Name: "{autodesktop}\School Timetable"; Filename: "{app}\TimetableSystem.exe"; Check: IsModern
Name: "{group}\School Timetable"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\start.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\php\php.exe"; Check: not IsModern
Name: "{autodesktop}\School Timetable"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\start.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\php\php.exe"; Check: not IsModern
Name: "{group}\Stop School Timetable server"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\stop.vbs"""; Check: not IsModern

[Run]
Filename: "{app}\TimetableSystem.exe"; Description: "Launch"; Flags: nowait postinstall skipifsilent; Check: IsModern
Filename: "{sys}\wscript.exe"; Parameters: """{app}\start.vbs"""; Description: "Launch"; Flags: nowait postinstall skipifsilent; Check: not IsModern

[UninstallRun]
Filename: "{sys}\wbem\wmic.exe"; Parameters: "process where ""name='php.exe' and commandline like '%47821%'"" call terminate"; Flags: runhidden; RunOnceId: "StopPhpServer"

[Code]
function IsModern: Boolean;
var
  V: TWindowsVersion;
begin
  GetWindowsVersionEx(V);
  Result := IsWin64 and (V.Major >= 10);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  R: Integer;
begin
  { سێرڤەری PHP ی وەشانی کۆن ڕادەگرین بۆ ئەوەی فایلەکان قوفڵ نەبن }
  Exec(ExpandConstant('{sys}\wbem\wmic.exe'),
       'process where "name=''php.exe'' and commandline like ''%47821%''" call terminate',
       '', SW_HIDE, ewWaitUntilTerminated, R);
  Result := '';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = wpSelectDir) and IsModern and (Pos(' ', WizardDirValue) > 0) then
  begin
    MsgBox('The install folder must not contain spaces (for example C:\TimetableSystem).', mbError, MB_OK);
    Result := False;
  end;
end;
