' ============================================================
' School Timetable - launcher for the "All Windows" edition
' Works on Windows 7, 8, 8.1, 10, 11 (32-bit and 64-bit).
' Starts the built-in PHP server (hidden) and opens the app in
' Edge/Chrome app mode, or in the default browser.
' ============================================================
Option Explicit
Dim sh, fso, dir, port, url, i
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
dir = fso.GetParentFolderName(WScript.ScriptFullName)
port = "47821"
url = "http://127.0.0.1:" & port & "/"

If Not fso.FileExists(dir & "\php\php.exe") Then
  MsgBox "php\php.exe was not found next to start.vbs.", vbCritical, "School Timetable"
  WScript.Quit 1
End If

If Not Alive() Then
  ' extension_dir = "ext" is relative to the working directory
  sh.CurrentDirectory = dir & "\php"
  sh.Run """" & dir & "\php\php.exe"" -c """ & dir & "\php\php.ini"" -S 127.0.0.1:" & port & " -t """ & dir & "\www""", 0, False
  For i = 1 To 75
    WScript.Sleep 200
    If Alive() Then Exit For
  Next
  If Not Alive() Then
    MsgBox "The program could not start (port " & port & " may be in use)." & vbCrLf & _
           "If this keeps happening, restart the computer.", vbExclamation, "School Timetable"
    WScript.Quit 1
  End If
End If
OpenApp

Function Alive()
  Dim h
  On Error Resume Next
  Set h = CreateObject("MSXML2.ServerXMLHTTP")
  h.setTimeouts 500, 500, 500, 2000
  h.Open "GET", url & "api/index.php?action=settings_get", False
  h.Send
  Alive = (Err.Number = 0)
  If Alive Then Alive = (h.Status = 200)
  Err.Clear
End Function

Function Env(name)
  Env = sh.ExpandEnvironmentStrings("%" & name & "%")
End Function

Sub OpenApp()
  ' Edge or Chrome in "app" mode = a window without an address bar
  Dim paths, p
  paths = Array( _
    Env("ProgramFiles(x86)") & "\Microsoft\Edge\Application\msedge.exe", _
    Env("ProgramFiles") & "\Microsoft\Edge\Application\msedge.exe", _
    Env("ProgramFiles") & "\Google\Chrome\Application\chrome.exe", _
    Env("ProgramFiles(x86)") & "\Google\Chrome\Application\chrome.exe", _
    Env("LocalAppData") & "\Google\Chrome\Application\chrome.exe")
  For Each p In paths
    If fso.FileExists(p) Then
      sh.Run """" & p & """ --app=" & url, 1, False
      Exit Sub
    End If
  Next
  ' Fallback: default browser (Internet Explorer is NOT supported)
  sh.Run url, 1, False
End Sub
