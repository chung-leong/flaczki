package lex.geom 
{
	//THX org.rje.glaze.engine.math
	public class V2
	{

		public var x:Number;
		public var y:Number;

		public function V2(_x:Number = 0, _y:Number =0) 
		{
			x = _x;
			y = _y;
		}


		public function clone():V2 {
			return new V2(x, y);
		}

		/*создает новый вектор*/
		public static function create(a:V2, b:V2):V2 {
			return new V2(a.x - b.x, a.y - b.y);
		}

		/*копирование х и y в result
		 * */
		public function copy(b:V2):void {
			x = b.x;
			y = b.y;
		}

		/*сложение
		 * */
		public function plus(b:V2):void {
			x += b.x;
			y += b.y;
		}

		/*вычитание
		 * */
		public function minus(b:V2):void {
			x -= b.x;
			y -= b.y;
		}

		/*Правая нормаль
		 * */
		public function rightNormal():void {
			var _x:Number = x;
			x = -y;
			y = _x; 
		}

		/*Левая нормаль
		 * */
		public function leftNormal():void {
			var _x:Number = x;
			x = y;
			y = -_x; 
		}

		/*умножение на число
		 * */
		public  function scale(n:Number):void {
			x *= n;
			y *= n; 
		}

		/*скалярное умножение векторов*/
		public function dot(b:V2):Number {
			return x * b.x + y * b.y;
		}

		/*деление вектора на число*/
		public function div(s:Number):void {
			if (s == 0) s = 0.0001;
			x /= s; 
			y /= s;
		}

		public function cross(b:V2):Number {
			return x * b.y - y * b.x;
		}

		/*длина вектора*/
		public function length():Number {
			return Math.sqrt(x * x + y * y);
		}

		/*расстояние от точки до точки*/
		public function distance(v:V2):Number {
			var _x:Number = x - v.x;
			var _y:Number = y - v.y;
			return Math.sqrt(_x * _x + _y * _y);
		}

		/*приведение вектора к единичному виду*/
		public function normalize():void {
			 var m:Number = length();
			 if (m == 0) m = 0.0001;
			 scale(1 / m);
		}

		/*returns the scalar projection of this vector onto v
		 *//*
		public  function scalarProjectionOnto(v0:V2,v1:V2):Number {
			return (v0.x*v1.x + v0.y*v1.y)/v1.length;
		}*/
		
		public function toString():String {
			return ('{' + x + ',' + y + '}');
		}

	}

}

