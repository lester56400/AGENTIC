import os
import shutil
import datetime

timestamp = datetime.datetime.now().strftime("%Y%md_%H%M")
dest = os.path.join(os.path.expanduser("~"), "Downloads", f"upload_{timestamp}")

if not os.path.exists(dest):
    os.makedirs(dest)

whitelist = ['assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php']

for item in whitelist:
    src_path = os.path.abspath(item)
    if os.path.exists(src_path):
        dest_path = os.path.join(dest, item)
        if os.path.isdir(src_path):
            shutil.copytree(src_path, dest_path, dirs_exist_ok=True)
        else:
            shutil.copy2(src_path, dest_path)

print(f"PLUGIN EXPORTE : {dest}")
os.startfile(dest)
