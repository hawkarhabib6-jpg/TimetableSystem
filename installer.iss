#define AppDir GetEnv('PHPDESKTOP_DIR')
#ifndef AppVersion
  #define AppVersion "2.0.0"
#endif

[Setup]
AppId={{6F1B7C2E-4A1D-4E8B-9C35-2D7A1B0E5F42}
AppName=School Timetable System
AppVersion={#AppVersion}
AppPublisher=TimetableSystem
; بۆ هەر بەکارهێنەرێک جیا دادەمەزرێت → پێویستی بە مافی Administrator نییە
; و بەرنامەکە دەتوانێت لە فۆڵدەری خۆیدا بنووسێت (بنکەی زانیاری، فایلی کاتی)
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
; PHP Desktop ناتوانێت PHP بکاتەوە ئەگەر ڕێڕەوەکە بۆشایی (space)ی تێدابێت،
; بۆ نموونە C:\Users\Ahmad Ali\... ، بۆیە ڕێڕەوێکی بێ بۆشایی بەکاردەهێنین
DefaultDirName={sd}\TimetableSystem
DefaultGroupName=TimetableSystem
DisableProgramGroupPage=yes
UninstallDisplayIcon={app}\TimetableSystem.exe
OutputBaseFilename=TimetableSystem-Setup
OutputDir=.
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible

[Files]
Source: "{#AppDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs

[Dirs]
; ئەگەر بۆ هەموو بەکارهێنەران لە Program Files دامەزرا، فۆڵدەری کاتی PHP دەبێت بنووسرێت
Name: "{app}\www\tmp"; Permissions: users-modify

[Icons]
Name: "{group}\School Timetable"; Filename: "{app}\TimetableSystem.exe"
Name: "{autodesktop}\School Timetable"; Filename: "{app}\TimetableSystem.exe"

[Run]
Filename: "{app}\TimetableSystem.exe"; Description: "Launch"; Flags: nowait postinstall skipifsilent

[Code]
function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = wpSelectDir) and (Pos(' ', WizardDirValue) > 0) then
  begin
    MsgBox('The install folder must not contain spaces (for example C:\TimetableSystem).', mbError, MB_OK);
    Result := False;
  end;
end;
