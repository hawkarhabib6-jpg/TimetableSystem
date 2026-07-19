#define AppDir GetEnv('PHPDESKTOP_DIR')

[Setup]
AppName=School Timetable System
AppVersion=1.0
DefaultDirName={autopf}\TimetableSystem
DefaultGroupName=TimetableSystem
OutputBaseFilename=TimetableSystem-Setup
OutputDir=.
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64

[Files]
Source: "{#AppDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs

[Icons]
Name: "{group}\School Timetable"; Filename: "{app}\TimetableSystem.exe"
Name: "{autodesktop}\School Timetable"; Filename: "{app}\TimetableSystem.exe"

[Run]
Filename: "{app}\TimetableSystem.exe"; Description: "Launch"; Flags: nowait postinstall skipifsilent
