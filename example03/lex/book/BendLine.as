package lex.book 
{
	import lex.geom.V2;
	/**
	 * ...
	 * @author Alex Lexcuk http://www.murmadillo.tut.su
	 */
	public class BendLine
	{
		private var s:BookStyle;
		private var va:V2 = new V2();
		private var vb:V2 = new V2();
		private var inter:V2 = new V2();
		private var mid:V2 = new V2();
		private var midR:V2 = new V2();
		public var interTop:V2 = new V2();
		
		public var interBottom:V2 = new V2();
		public function BendLine(s:BookStyle) 
		{
			this.s = s;
		}

		/*расчет перегиба страницы pageP - положение мыши на книге
		 * con0 - перелистываемый угол страницы
		 * результат пересечения interTop и interBottom
		 * */
		public function calcBendLine(pageP:V2, con0:V2):void {
			
			mid.copy(con0);
			
			mid.minus(pageP);//вектор от уголка книги на мышь
			
			mid.scale(0.5);
			
			midR.copy(mid);
			
			midR.leftNormal();//вектор от углка книги на мышь, повернутый на 90
			
			mid.plus(pageP);
			
			midR.plus(mid);
			
			var res:V2;
			var con1:V2;
			if (con0 == s.bl) con1 = s.tl; else con1 = s.tr;
			
			res = lineIntersect(s.midBottom, s.br, mid, midR);
			
			interBottom.copy(res);//нижняя пересекающаяся точка
			
			res = lineIntersect(con0, con1, mid, midR);
			
			if (res.y > 0 && res.y < s.height) interTop.copy(res); else{
				res = lineIntersect(s.midTop, s.tr, mid, midR);
				interTop.copy(res);//нижняя пересекающаяся точка
			}
		}

		private function lineIntersect(a0:V2, a1:V2, b0:V2, b1:V2):V2 {
			// http://forum.vingrad.ru/faq/topic-157574.html
			var crossA:Number, crossB:Number, div:Number;
			
			crossA = a0.cross(a1);
			crossB = b0.cross(b1);
			
			va.copy(a0);
			va.minus(a1);
			
			
			vb.copy(b0)
			vb.minus(b1);
			
			div = 1 / va.cross(vb);
			
			inter.x = (crossA*vb.x - va.x*crossB) * div;
			inter.y = (crossA*vb.y - va.y*crossB) * div;
			return inter;
		}
	}

}

