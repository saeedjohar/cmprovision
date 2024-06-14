<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScriptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $script = <<<'EOF'
#!/bin/sh
set -e

parted -s $STORAGE "resizepart 2 -1" "quit"
resize2fs -f $PART2

mkdir -p /mnt/boot /mnt/root
mount -t ext4 $PART2 /mnt/root
umount /mnt/root
mount -t vfat $PART1 /mnt/boot
sed -i 's| init=/usr/lib/raspi-config/init_resize\.sh||' /mnt/boot/cmdline.txt
umount /mnt/boot
EOF;

        DB::table('scripts')->insert([
            'name' => 'Resize ext4 partition',
            'script_type' => 'postinstall',
            'priority' => 50,
            'bg' => false,
            'script' => $script
        ]);

        $script = <<<'EOF'
#!/bin/sh
set -e

mkdir -p /mnt/boot
mount -t vfat $PART1 /mnt/boot
echo "dtoverlay=dwc2,dr_mode=host" >> /mnt/boot/config.txt
umount /mnt/boot
EOF;

        DB::table('scripts')->insert([
            'name' => 'Add dtoverlay=dwc2 to config.txt',
            'script_type' => 'postinstall',
            'priority' => 100,
            'bg' => false,
            'script' => $script
        ]);


        $script = <<<'EOF'
#!/bin/sh
set +e

MAXSIZEKB=`mmc extcsd read /dev/mmcblk0 | grep MAX_ENH_SIZE_MULT -A 1 | grep -o '[0-9]\+ '`
mmc enh_area set -y 0 $MAXSIZEKB /dev/mmcblk0
if [ $? -eq 0 ]; then
    reboot -f
fi
EOF;

        DB::table('scripts')->insert([
            'name' => 'Format eMMC as pSLC (one time settable only)',
            'script_type' => 'preinstall',
            'priority' => 100,
            'bg' => false,
            'script' => $script
        ]);
        
        $script = <<<'EOF'
#!/bin/sh
set -e

MODEM_PORT="/dev/ttyUSB3"
BAUD_RATE="9600" # Adjust the baud rate according to your modem's specifications

# Check if modem port exists
if [ ! -e "$MODEM_PORT" ]; then
    echo "Modem port $MODEM_PORT not found. Exiting."
    sleep 10
fi

# Open the modem port for reading and writing
exec 3<> "$MODEM_PORT" || { echo "Failed to open modem port $MODEM_PORT"; exit 1; }

# Set baud rate
stty -F "$MODEM_PORT" "$BAUD_RATE"

# Function to send AT command
send_at_command() {
    #echo "Sending AT command: $1"
    echo "$1" > "$MODEM_PORT"
}

# Send AT command
send_at_command "AT+GSN"

# Array to store responses

responses=""

# Read response until "OK" is received
response=""


start_time=$(date +%s)
while true; do
    read -r response < "$MODEM_PORT"
    if [ -z "$response" ]; then
        current_time=$(date +%s)
        elapsed_time=$((current_time - start_time))
        if [ "$elapsed_time" -ge 5 ]; then  # Timeout after 5 seconds
            echo "Timeout: No response received within 5 seconds"
            break
        fi
    elif [ "$(expr substr "$response" 1 1)" = "8" ] && [ "$(echo "$response" | wc -c)" -eq 16 ]; then
        echo "$response"
	break
    fi
done

# Close the modem port
exec 3>&-
EOF;

        DB::table('scripts')->insert([
            'name' => 'Get IMEI',
            'script_type' => 'postinstall',
            'priority' => 150,
            'bg' => false,
            'script' => $script
        ]);

        $script = <<<'EOF'
        #!/bin/sh

        set -e
        
        MODEM_PORT="/dev/ttyUSB3"
        BAUD_RATE="9600" # Adjust the baud rate according to your modem's specifications
        
        # Check if modem port exists
        if [ ! -e "$MODEM_PORT" ]; then
            echo "Modem port $MODEM_PORT not found. Exiting."
            sleep 10
            exit 1
        fi
        
        # Open the modem port for reading and writing
        exec 3<> "$MODEM_PORT" || { echo "Failed to open modem port $MODEM_PORT"; exit 1; }
        
        # Set baud rate
        stty -F "$MODEM_PORT" "$BAUD_RATE"
        
        # Function to send AT command
        send_at_command() {
            echo -e "$1\r" > "$MODEM_PORT"
        }
        
        # Send AT command
        send_at_command "AT+ICCID"
        
        # Read response until the ICCID is received
        response=""
        start_time=$(date +%s)
        
        while true; do
            if read -r response < "$MODEM_PORT"; then
                if [ -n "$response" ]; then
                    if echo "$response" | grep -q "+ICCID:"; then
                        ICCID=$(echo "$response" | sed 's/^+ICCID: //')
                        if [ $(echo -n "$ICCID" | wc -c) -eq 20 ]; then
                            echo "$ICCID"
                            break
                        else
                            echo "Invalid ICCID length received: $ICCID"
                        fi
                    elif echo "$response" | grep -q "OK"; then
                        echo "Command succeeded but no ICCID found."
                        break
                    fi
                fi
            else
                current_time=$(date +%s)
                elapsed_time=$((current_time - start_time))
                if [ "$elapsed_time" -ge 5 ]; then  # Timeout after 5 seconds
                    echo "Timeout: No response received within 5 seconds"
                    break
                fi
            fi
        done
        
        # Close the modem port
        exec 3>&-
EOF;

        DB::table('scripts')->insert([
            'name' => 'Get ICCID',
            'script_type' => 'postinstall',
            'priority' => 500,
            'bg' => false,
            'script' => $script
        ]);
    }
}
