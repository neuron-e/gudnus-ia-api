import cv2
import numpy as np
import sys
import json
import traceback

def calcular_integridad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # Umbral adaptativo: considera que la zona útil es lo más claro
    mean_val = np.mean(gray)
    threshold = mean_val * 0.6  # 60% del promedio

    non_dark_pixels = np.count_nonzero(gray > threshold)
    total = gray.size

    return round((non_dark_pixels / total) * 100, 2)

def calcular_luminosidad(img):
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    brightness = hsv[:, :, 2]
    return round(np.mean(brightness), 5)

def calcular_uniformidad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return round(np.std(gray), 3)

def process_image(input_path, output_path):
    img = cv2.imread(input_path)

    # 🔎 Cálculo de métricas ANTES del procesamiento visual
    integridad = calcular_integridad(img)
    luminosidad = calcular_luminosidad(img)
    uniformidad = calcular_uniformidad(img)

    # 🔧 Proceso de recorte y enderezado
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    _, thresh = cv2.threshold(gray, 30, 255, cv2.THRESH_BINARY)
    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    panel_contour = max(contours, key=cv2.contourArea)

    rect = cv2.minAreaRect(panel_contour)
    box = cv2.boxPoints(rect).astype(int)

    angle = rect[2]
    if angle < -45:
        angle += 90

    center = rect[0]
    rot_matrix = cv2.getRotationMatrix2D(center, angle, 1.0)
    rotated = cv2.warpAffine(img, rot_matrix, (img.shape[1], img.shape[0]), flags=cv2.INTER_CUBIC)

    box = cv2.transform(np.array([box]), rot_matrix)[0].astype(int)
    x, y, w, h = cv2.boundingRect(box)
    cropped = rotated[y:y+h, x:x+w]

    # ☀️ Aumento de brillo
    hsv = cv2.cvtColor(cropped, cv2.COLOR_BGR2HSV)
    hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 40)
    bright = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)

    cv2.imwrite(output_path, bright)

    # ✅ Imprimir métricas en formato JSON
    print(json.dumps({
        "integridad": integridad,
        "luminosidad": luminosidad,
        "uniformidad": uniformidad
    }))

if __name__ == "__main__":
    try:
        if len(sys.argv) != 3:
            print("Uso: python process_image.py input_path output_path")
            sys.exit(1)

        process_image(sys.argv[1], sys.argv[2])
    except Exception as e:
        print("ERROR:", str(e))
        traceback.print_exc()
        sys.exit(1)
