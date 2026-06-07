import minimalmodbus
import serial
import time

# ⚠️ Change COM3 to YOUR port number
PORT          = 'COM5'
SLAVE_ADDRESS = 1
BAUDRATE      = 4800

sensor = minimalmodbus.Instrument(PORT, SLAVE_ADDRESS)
sensor.serial.baudrate = BAUDRATE
sensor.serial.bytesize = 8
sensor.serial.parity   = serial.PARITY_NONE
sensor.serial.stopbits = 1
sensor.serial.timeout  = 1
sensor.mode = minimalmodbus.MODE_RTU

print("✅ Connected! Reading sensor...\n")

while True:
    try:
        humidity    = sensor.read_register(0x0000, 1)
        temperature = sensor.read_register(0x0001, 1)
        ec          = sensor.read_register(0x0002, 0)
        ph          = sensor.read_register(0x0003, 1)
        nitrogen    = sensor.read_register(0x0004, 0)
        phosphorus  = sensor.read_register(0x0005, 0)
        potassium   = sensor.read_register(0x0006, 0)

        print("================================")
        print(f"  💧 Humidity     : {humidity} %")
        print(f"  🌡️  Temperature  : {temperature} °C")
        print(f"  ⚡ EC           : {ec} µS/cm")
        print(f"  🧪 pH           : {ph}")
        print(f"  🌿 Nitrogen  (N): {nitrogen} mg/kg")
        print(f"  🌿 Phosphorus(P): {phosphorus} mg/kg")
        print(f"  🌿 Potassium (K): {potassium} mg/kg")
        print("================================\n")

    except Exception as e:
        print(f"❌ Error: {e}")

    time.sleep(2)