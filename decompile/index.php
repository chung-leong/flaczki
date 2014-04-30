<html>
<head>
<title>Online ActionScript Decompiler</title>
<body>
<h1>Online ActionScript Decompiler</h1>
<p>
This is an experimental ActionScript decompiler. It is able to decompile AS1, AS2, and AS3.
</p>

<hr>
<h2>Method 1: Upload a SWF file</h2>
<p>
	<form action="decompile.php" method="post" enctype="multipart/form-data">
	<label for="file">Filename:</label> <input type="file" name="file" id="file"/> <input type="submit" name="submit" value="Upload" />
	</form>
</p>
<h2>Method 2: Enter a URL to of a SWF file</h2>
<p>
	<form action="decompile.php" method="post">
	<label for="url">URL:</label> <input type="text" name="url" id="url" size="60"/> <input type="submit" name="submit" value="Submit" />
	</form>
</p>

<hr>
<h2>Notes</h2>
<ul>
	<li>Variable declarations in AS2 classes will often be incomplete. The decompiler currently only attaches variables that are initialized to default values.</li>
	<li>AS2 interface declarations are always empty, as they are not fully realized classes.</li>
	<li>Functions in AS2 classes will be always public.</li>
	<li>AS3 try/catch blocks are not currently processed.</li>
	<li>For loops are currently not reconstructed. They will show up as while loops instead.</li>
	<li>The code block beneath the static variable declarations is an AS3 class's static constructor. Initialization of static variables and constants happens in there.</li>
</ul>
<!-- Start of SimpleHitCounter Code -->
<div align="left"><a href="http://www.simplehitcounter.com" target="_blank"><img src="http://simplehitcounter.com/hit.php?uid=1323621&f=65280&b=0" border="0" height="18" width="83" alt="web counter"></a><br></div>
<!-- End of SimpleHitCounter Code -->
</body>
</html>