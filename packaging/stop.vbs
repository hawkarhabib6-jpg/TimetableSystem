' Stops the hidden PHP server of the "All Windows" edition.
Dim sh: Set sh = CreateObject("WScript.Shell")
sh.Run "wmic process where ""name='php.exe' and commandline like '%47821%'"" call terminate", 0, True
