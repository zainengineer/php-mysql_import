Create your own configuration under `config/`

Optionally install ssh extension `apt-get install php-ssh2` or `apt-get install php56-ssh2`

Execute command like `/config/commands/zain/mysql_import_project.sh`

Sample Contents:

    echo "building download"
    php -f mysql_import.php project/project download
    echo "starting download"
    bash runtime/project/project/download.bat
    
    echo "building unzip command"
    php -f mysql_import.php project/project unzipAndImport
    echo "unzipping"
    bash runtime/project/project/deflate_and_import.bash
    echo "complete"